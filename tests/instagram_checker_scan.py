"""Native metadata API regression tests; no Instagram network access or real cookies."""
import contextlib
import importlib.util
import io
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

from gallery_dl import exception
from gallery_dl.extractor.instagram import InstagramAPI
from gallery_dl.extractor.common import CACHE_MEMORY
from requests import Response

spec=importlib.util.spec_from_file_location('checker_scan',Path(__file__).parents[1]/'bin/instagram-check.py')
scan=importlib.util.module_from_spec(spec)
spec.loader.exec_module(scan)


class CheckerScanTests(unittest.TestCase):
    def setUp(self):
        CACHE_MEMORY.clear()
        self.temp=tempfile.TemporaryDirectory()
        self.cookie=Path(self.temp.name)/'session.txt'
        self.cookie.write_text('# Netscape HTTP Cookie File\n.instagram.com\tTRUE\t/\tTRUE\t0\tsessionid\tCHECKER_FIXTURE\n')
        self.user={'pk':'7','id':'7','username':'fixture'}
        self.posts=[{'pk':'100','code':'CHECK100','taken_at':10,'user':dict(self.user)}]
        self.stories=[{'pk':'200','taken_at':10}]
        self.operations=[]
        self.webpages=0
        self.limited=False
        self.bad_owner=False
        test=self
        def webpage(api,path):
            test.webpages+=1
            return '<link rel="canonical" href="https://www.instagram.com/fixture/">"profile_id":"7"'
        def pagination(api,op,variables,fieldname,path=''):
            test.operations.append((op,variables))
            if test.limited:
                response=Response();response.status_code=429;response._content=b'';response._content_consumed=True
                raise exception.HttpError('HTTP 429',response)
            if op=='PolarisProfilePostsTabContentQuery_connection':
                test.assertEqual(variables['username'],'fixture')
                return iter(test.posts)
            if op=='PolarisStoriesV3ReelPageGalleryQuery':
                test.assertEqual(variables['reel_ids'],('7',))
                user=dict(test.user,pk='8',id='8') if test.bad_owner else dict(test.user)
                return iter([{'id':'7','user':user,'items':test.stories}])
            raise AssertionError('Unexpected API')
        self.patches=[patch.object(InstagramAPI,'_webpage',webpage),patch.object(InstagramAPI,'_pagination_graphql',pagination),patch.object(InstagramAPI,'user_by_id',side_effect=AssertionError('Full profile must never be queried'))]
        for p in self.patches:p.start()
    def tearDown(self):
        for p in reversed(self.patches):p.stop()
        self.temp.cleanup()
    def args(self,**kw):return SimpleNamespace(username='fixture',cookies=str(self.cookie),account_id='',mode=3,proxy=None,**kw)
    def test_native_feed_and_stories_share_only_a_minimal_account_identity(self):
        result=scan.snapshot(self.args())
        self.assertEqual(result['account_id'],'7')
        self.assertEqual(result['posts'][0]['id'],'100')
        self.assertEqual(result['stories'][0]['url'],'https://www.instagram.com/stories/fixture/200/')
        self.assertEqual(self.webpages,1)
        self.assertEqual(len(self.operations),2)
    def test_cached_identity_avoids_the_extra_account_lookup(self):
        args=self.args();args.account_id='7';result=scan.snapshot(args)
        self.assertEqual(result['account_id'],'7');self.assertEqual(self.webpages,0)
    def test_one_mode_never_queries_the_other_content_type(self):
        args=self.args();args.mode=1;result=scan.snapshot(args)
        self.assertEqual(result['stories'],[]);self.assertEqual(len(self.operations),1)
    def test_only_twenty_four_latest_post_records_are_examined(self):
        self.posts=[dict(self.posts[0],pk=str(100+i),code='CHECK'+str(100+i),taken_at=100-i)for i in range(30)]
        result=scan.snapshot(self.args());self.assertEqual(len(result['posts']),24)
        self.assertLess(result['posts'][0]['time'],result['posts'][-1]['time'])
    def test_foreign_post_owner_is_rejected(self):
        self.posts[0]['user']={'id':'8','username':'someone_else'}
        with self.assertRaises(scan.InvalidSnapshot):scan.snapshot(self.args())
    def test_foreign_reel_owner_is_rejected(self):
        self.bad_owner=True
        with self.assertRaises(scan.InvalidSnapshot):scan.snapshot(self.args())
    def test_story_limit_and_duplicates_fail_as_a_whole(self):
        for items in [[{'pk':'200'},{'pk':'200'}],[{'pk':str(200+i)}for i in range(101)]]:
            self.stories=items
            with self.assertRaises(scan.InvalidSnapshot):scan.snapshot(self.args())
    def test_rate_limit_stops_before_trying_any_other_api(self):
        self.limited=True
        with self.assertRaises(exception.HttpError):scan.snapshot(self.args())
        self.assertEqual(len(self.operations),1)
    def test_bad_username_cannot_become_a_profile_or_foreign_url(self):
        for username in ['https://instagram.com/fixture','fixture/extra','../fixture']:
            args=self.args();args.username=username
            with self.assertRaises(scan.InvalidSnapshot):scan.snapshot(args)
        self.assertEqual(self.operations,[])


if __name__=='__main__':unittest.main()
