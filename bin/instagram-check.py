"""Metadata-only Instagram checker using the pinned native gallery-dl APIs."""
import argparse
import importlib.util
import itertools
import json
import logging
import re
import sys
from pathlib import Path
from html.parser import HTMLParser
from urllib.parse import urlsplit

from gallery_dl import config, exception, extractor


class InvalidSnapshot(Exception):
    pass


def owner_matches(user, uid, username):
    if not isinstance(user, dict):
        return False
    owner = str(user.get('pk') or user.get('id') or '')
    name = str(user.get('username') or '').lower()
    return (owner == uid if owner else name == username) and (not name or name == username)


def avatar_url(url):
    if not isinstance(url, str) or len(url) > 4096 or re.search(r'[\x00-\x20\x7f]', url):
        return False
    try:
        p = urlsplit(url)
        return p.scheme == 'https' and not p.username and not p.password and p.port is None and (
            (p.hostname or '').endswith('.cdninstagram.com') or (p.hostname or '').endswith('.fbcdn.net'))
    except ValueError:
        return False


def user_avatar(user, uid, username):
    if not isinstance(user, dict) or str(user.get('pk') or user.get('id') or '') != uid or str(user.get('username') or '').lower() != username:
        return ''
    hd = user.get('hd_profile_pic_url_info')
    for url in (user.get('profile_pic_url_hd'), hd.get('url') if isinstance(hd, dict) else '', user.get('profile_pic_url')):
        if avatar_url(url):
            return url
    return ''


class ProfileImages(HTMLParser):
    def __init__(self):
        super().__init__()
        self.images, self.documents, self.parts = set(), [], []
        self.script = False

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == 'meta' and attrs.get('property') == 'og:image' and avatar_url(attrs.get('content')):
            self.images.add(attrs['content'])
        if tag == 'script':
            self.script = attrs.get('type') == 'application/json' and len(self.documents) < 24
            self.parts = []

    def handle_data(self, data):
        if self.script:
            self.parts.append(data)

    def handle_endtag(self, tag):
        if tag == 'script' and self.script:
            try:
                self.documents.append(json.loads(''.join(self.parts)))
            except (ValueError, RecursionError):
                pass
            self.script, self.parts = False, []


def page_avatar(page, uid, username):
    # The caller verifies the canonical username and unique profile_id on this exact page first.
    parser = ProfileImages()
    parser.feed(page)
    stack = [(doc, 0) for doc in parser.documents]
    visited = 0
    while stack:
        value, depth = stack.pop()
        visited += 1
        if visited > 60000 or depth > 60:
            return ''
        if isinstance(value, dict):
            image = user_avatar(value, uid, username)
            if image:
                return image
            stack.extend((v, depth + 1) for v in value.values() if isinstance(v, (dict, list)))
        elif isinstance(value, list):
            stack.extend((v, depth + 1) for v in value if isinstance(v, (dict, list)))
    return next(iter(parser.images)) if len(parser.images) == 1 else ''


def identity_helper():
    spec = importlib.util.spec_from_file_location('ciro_instagram', Path(__file__).with_name('instagram-download.py'))
    helper = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(helper)
    return helper


def checker_session(args):
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
    return username, e


def profile(args):
    username, e = checker_session(args)
    user = identity_helper().story_identity(e)
    uid = str(user['id'])
    if args.account_id and uid != args.account_id:
        raise InvalidSnapshot()
    page = e.cache(e.api._webpage, '/' + username)
    return {'account_id': uid, 'avatar': page_avatar(page, uid, username)}


def snapshot(args):
    if args.mode not in (1, 2, 3):
        raise InvalidSnapshot()
    username, e = checker_session(args)
    uid = args.account_id
    image = ''
    if not uid:
        uid = str(identity_helper().story_identity(e)['id'])
        image = page_avatar(e.cache(e.api._webpage, '/' + username), uid, username)
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
            image = image or user_avatar(post.get('user') or post.get('owner'), uid, username)
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
        if own:
            image = image or user_avatar(own[0].get('user'), uid, username)
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
    result = {'account_id': uid, 'mode': args.mode, 'posts': sorted(posts, key=lambda i: i['time']), 'stories': sorted(stories, key=lambda i: i['time'])}
    if image:
        result['avatar'] = image
    return result


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--username', required=True)
    parser.add_argument('--cookies', required=True)
    parser.add_argument('--account-id', default='')
    parser.add_argument('--mode', type=int, choices=[1, 2, 3], default=0)
    parser.add_argument('--profile-only', action='store_true')
    parser.add_argument('--proxy')
    args = parser.parse_args()
    try:
        print(json.dumps(profile(args) if args.profile_only else snapshot(args), ensure_ascii=True))
        return 0
    except exception.HttpError as error:
        status = error.response.status_code if error.response is not None else 0
        reason = 'blocked' if status == 429 else 'cookies' if status in (401, 403) else 'restricted' if status == 404 else 'network'
    except exception.AuthenticationError:
        reason = 'cookies'
    except exception.NotFoundError:
        reason = 'restricted'
    except exception.ExtractionError as error:
        status = re.search(r'Instagram story identity HTTP ([0-9]{1,3})\b', str(error))
        code = int(status[1]) if status else None
        reason = 'blocked' if code == 429 else 'cookies' if code in (401, 403) else 'restricted' if code == 404 else 'network' if code == 0 else 'format'
    except Exception:
        reason = 'format'
    print('CHECKER_ERROR ' + reason, file=sys.stderr)
    return 1


if __name__ == '__main__':
    sys.exit(main())
