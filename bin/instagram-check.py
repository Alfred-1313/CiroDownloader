"""Metadata-only Instagram checker using the pinned native gallery-dl APIs."""
import argparse
import importlib.util
import itertools
import json
import logging
import re
import sys
from pathlib import Path

from gallery_dl import config, exception, extractor


class InvalidSnapshot(Exception):
    pass


def owner_matches(user, uid, username):
    if not isinstance(user, dict):
        return False
    owner = str(user.get('pk') or user.get('id') or '')
    name = str(user.get('username') or '').lower()
    return (owner == uid if owner else name == username) and (not name or name == username)


def snapshot(args):
    username = args.username.lower()
    if not re.fullmatch(r'[a-z0-9_](?:[a-z0-9_.]{0,28}[a-z0-9_])?', username):
        raise InvalidSnapshot()
    logging.disable(logging.CRITICAL)
    config.set(('cache',), 'file', ':memory:')
    for key, value in [('cookies', args.cookies), ('retries', 0), ('timeout', 10), ('sleep-request', 1), ('sleep', 0)]:
        config.set(('extractor', 'instagram'), key, value)
    if args.proxy:
        config.set(('extractor', 'instagram'), 'proxy', args.proxy)
    e = extractor.find('https://www.instagram.com/stories/' + username + '/')
    e.initialize()
    e.login()
    uid = args.account_id
    if not uid:
        spec = importlib.util.spec_from_file_location('ciro_instagram', Path(__file__).with_name('instagram-download.py'))
        helper = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(helper)
        uid = str(helper.story_identity(e)['id'])
    if not re.fullmatch(r'[0-9]{1,24}', uid):
        raise InvalidSnapshot()
    user = {'id': uid, 'pk': uid, 'username': username}
    e._assign_user(user)
    # user_feed only needs the username; never issue the full profile-by-ID query.
    e.api.user = lambda handle, check_private=True: user
    posts = []
    if args.mode & 1:
        seen = set()
        for post in itertools.islice(e.api.user_feed(username), 24):
            if not owner_matches(post.get('user') or post.get('owner'), uid, username):
                raise InvalidSnapshot()
            pk = str(post.get('pk') or post.get('id') or '').split('_', 1)[0]
            code = post.get('code', '')
            if not re.fullmatch(r'[0-9]{1,30}', pk) or not re.fullmatch(r'[A-Za-z0-9_-]{5,64}', code):
                raise InvalidSnapshot()
            if pk not in seen:
                posts.append({'id': pk, 'url': 'https://www.instagram.com/p/' + code + '/', 'time': int(post.get('taken_at') or 0)})
                seen.add(pk)
    stories = []
    if args.mode & 2:
        e._cursor = None
        reels = list(itertools.islice(e.api.reels_media((uid,)), 6))
        if len(reels) > 5:
            raise InvalidSnapshot()
        own = [r for r in reels if str(r.get('id') or '') == uid and owner_matches(r.get('user'), uid, username)]
        if reels and len(own) != 1:
            raise InvalidSnapshot()
        items = list(own[0].get('items') or ()) if own else []
        if len(items) > 100:
            raise InvalidSnapshot()
        seen = set()
        for item in items:
            pk = str(item.get('pk') or '').split('_', 1)[0]
            if not re.fullmatch(r'[0-9]{1,30}', pk) or pk in seen or ('user' in item and not owner_matches(item['user'], uid, username)):
                raise InvalidSnapshot()
            stories.append({'id': pk, 'url': f'https://www.instagram.com/stories/{username}/{pk}/', 'time': int(item.get('taken_at') or 0)})
            seen.add(pk)
    return {'account_id': uid, 'mode': args.mode, 'posts': sorted(posts, key=lambda i: i['time']), 'stories': sorted(stories, key=lambda i: i['time'])}


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--username', required=True)
    parser.add_argument('--cookies', required=True)
    parser.add_argument('--account-id', default='')
    parser.add_argument('--mode', type=int, choices=[1, 2, 3], required=True)
    parser.add_argument('--proxy')
    args = parser.parse_args()
    try:
        print(json.dumps(snapshot(args), ensure_ascii=True))
        return 0
    except exception.HttpError as error:
        status = error.response.status_code if error.response is not None else 0
        reason = 'blocked' if status == 429 else 'cookies' if status in (401, 403) else 'restricted' if status == 404 else 'network'
    except exception.AuthenticationError:
        reason = 'cookies'
    except exception.NotFoundError:
        reason = 'restricted'
    except Exception:
        reason = 'format'
    print('CHECKER_ERROR ' + reason, file=sys.stderr)
    return 1


if __name__ == '__main__':
    sys.exit(main())
