import importlib.util
import json
from pathlib import Path
from types import SimpleNamespace
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('checker', Path(__file__).resolve().parents[1] / 'bin/youtube-check.py')
checker = importlib.util.module_from_spec(spec)
spec.loader.exec_module(checker)
ACCOUNT = 'UC' + 'A' * 22


def page(kind, nodes=(), account=ACCOUNT):
    path = {'video': 'videos', 'short': 'shorts', 'post': 'posts'}[kind]
    return {'metadata': {'channelMetadataRenderer': {'externalId': account}}, 'contents': {'twoColumnBrowseResultsRenderer': {'tabs': [
        {'tabRenderer': {'endpoint': {'commandMetadata': {'webCommandMetadata': {'url': '/channel/' + account + '/' + path}}}, 'selected': True, 'content': {'items': list(nodes)}}}]}},
        'unrelated': {'videoRenderer': {'videoId': 'ZZZZZZZZZZZ'}}}


class Response:
    def __init__(self, data, code=200):
        self.status_code, self.headers = code, {}
        self.body = ('<script>var ytInitialData = ' + json.dumps(data) + ';</script>').encode()
    def iter_content(self, size):
        yield self.body
    def close(self):
        pass


class CheckerTests(unittest.TestCase):
    def test_own_tab_only_and_live_upcoming_excluded(self):
        data = page('video', [{'videoRenderer': {'videoId': 'AAAAAAAAAAA'}}, {'videoRenderer': {'videoId': 'BBBBBBBBBBB', 'upcomingEventData': {'startTime': '9999999999'}}}, {'videoRenderer': {'videoId': 'CCCCCCCCCCC', 'badges': [{'metadataBadgeRenderer': {'style': 'BADGE_STYLE_TYPE_LIVE_NOW'}}]}}])
        account, items = checker.parse(data, 'video')
        self.assertEqual(account, ACCOUNT)
        self.assertEqual([x['id'] for x in items], ['AAAAAAAAAAA'])
        self.assertEqual(items[0]['url'], 'https://www.youtube.com/watch?v=AAAAAAAAAAA')

    def test_shorts_and_posts_have_separate_validated_ids(self):
        _, shorts = checker.parse(page('short', [{'shortsLockupViewModel': {'onTap': {'innertubeCommand': {'reelWatchEndpoint': {'videoId': 'BBBBBBBBBBB'}}}}}]), 'short')
        self.assertEqual(shorts[0]['url'], 'https://www.youtube.com/shorts/BBBBBBBBBBB')
        post = 'Ug' + 'P' * 20
        _, items = checker.parse(page('post', [{'backstagePostThreadRenderer': {'post': {'backstagePostRenderer': {'postId': post}}}}]), 'post')
        self.assertEqual(items[0]['url'], 'https://www.youtube.com/post/' + post)
        with self.assertRaises(checker.posts.PostError):
            checker.parse(page('video', [{'videoRenderer': {'videoId': '../foreign'}}]), 'video')

    def test_bounds_and_identity(self):
        nodes = [{'videoRenderer': {'videoId': f'{n:011d}'}} for n in range(40)]
        self.assertEqual(len(checker.parse(page('video', nodes), 'video')[1]), 24)
        with self.assertRaises(checker.posts.PostError):
            checker.parse(page('video'), 'video', 'UC' + 'B' * 22)
        with self.assertRaises(checker.posts.PostError):
            checker.parse({'contents': {}}, 'video')
        # A valid channel with no Shorts tab has an authenticated empty snapshot for this kind.
        self.assertEqual(checker.parse(page('video'), 'short')[1], [])

    def test_native_initial_data_scan_uses_stable_channel_and_stops_on_429(self):
        seen = []
        class Session:
            def get(self, url, **kwargs):
                seen.append(url)
                kind = {'videos': 'video', 'shorts': 'short', 'posts': 'post'}[url.rsplit('/', 1)[-1]]
                return Response(page(kind))
        args = SimpleNamespace(channel='@youtube', mode=7, proxy=None, cookies=None)
        with patch.object(checker.posts, 'http_session', return_value=Session()):
            result = checker.scan(args)
        self.assertEqual(result, {'account_id': ACCOUNT, 'mode': 7, 'videos': [], 'shorts': [], 'posts': []})
        self.assertEqual(len(seen), 3)
        self.assertIn('/channel/' + ACCOUNT + '/shorts', seen[1])
        seen.clear()
        class Blocked:
            def get(self, url, **kwargs):
                seen.append(url)
                return Response({}, 429)
        with patch.object(checker.posts, 'http_session', return_value=Blocked()):
            with self.assertRaises(checker.posts.PostError) as error:
                checker.scan(args)
        self.assertEqual(error.exception.reason, 'blocked')
        self.assertEqual(len(seen), 1)


if __name__ == '__main__':
    unittest.main()
