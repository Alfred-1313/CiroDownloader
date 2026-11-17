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
    def test_identity_refusal_preserves_its_safe_rate_or_session_reason(self):
        import sys
        for status,reason in [(429,'blocked'),(401,'cookies'),(403,'cookies'),(404,'restricted'),(0,'network')]:
            CACHE_MEMORY.clear()
            response=Response();response.status_code=status;response._content=b'';response._content_consumed=True
            def refuse(api,path):
                raise exception.HttpError('Fixture refusal',response)
            with patch.object(InstagramAPI,'_webpage',refuse),patch.object(sys,'argv',['instagram-check.py','--username','fixture','--cookies',str(self.cookie),'--mode','3']),contextlib.redirect_stderr(io.StringIO())as out:
                self.assertEqual(scan.main(),1)
            self.assertEqual(out.getvalue().strip(),'CHECKER_ERROR '+reason)
    def test_profile_only_reads_one_verified_page_and_no_content_api(self):
        image='https://scontent.cdninstagram.com/avatar.jpg?x=1&y=2'
        def webpage(api,path):
            self.webpages+=1
            self.assertEqual(path,'/fixture')
            return '<link rel="canonical" href="https://www.instagram.com/fixture/">"profile_id":"7"<meta property="og:image" content="'+image.replace('&','&amp;')+'">'
        with patch.object(InstagramAPI,'_webpage',webpage): result=scan.profile(self.args())
        self.assertEqual(result,{'account_id':'7','avatar':image})
        self.assertEqual(self.webpages,1);self.assertEqual(self.operations,[])
    def test_profile_image_from_embedded_json_requires_exact_owner(self):
        import json
        good='https://scontent.cdninstagram.com/own.jpg'
        other='https://scontent.cdninstagram.com/neighbour.jpg'
        content='<script type="application/json">'+json.dumps({'users':[dict(self.user,pk='8',id='8',profile_pic_url_hd=other),dict(self.user,profile_pic_url_hd=good)]})+'</script>'
        self.assertEqual(scan.page_avatar(content,'7','fixture'),good)
        self.assertEqual(scan.page_avatar(content,'9','fixture'),'')
        self.assertEqual(scan.page_avatar(content,'7','another'),'')
    def test_profile_requires_the_target_page_and_persistent_identity(self):
        def foreign(api,path):return '<link rel="canonical" href="https://www.instagram.com/another/">"profile_id":"7"'
        with patch.object(InstagramAPI,'_webpage',foreign),self.assertRaises(exception.ExtractionError):scan.profile(self.args())
        args=self.args();args.account_id='8'
        with self.assertRaises(scan.InvalidSnapshot):scan.profile(args)
        self.assertEqual(self.operations,[])
    def test_profile_cli_stops_at_429_and_never_tries_a_full_profile_api(self):
        import sys
        response=Response();response.status_code=429;response._content=b'';response._content_consumed=True
        def refuse(api,path):
            self.webpages+=1;raise exception.HttpError('Fixture refusal',response)
        with patch.object(InstagramAPI,'_webpage',refuse),patch.object(sys,'argv',['instagram-check.py','--username','fixture','--cookies',str(self.cookie),'--profile-only']),contextlib.redirect_stderr(io.StringIO())as out:
            self.assertEqual(scan.main(),1)
        self.assertEqual(out.getvalue().strip(),'CHECKER_ERROR blocked');self.assertEqual(self.webpages,1);self.assertEqual(self.operations,[])
    def test_scan_reuses_own_post_avatar_without_a_new_page_lookup(self):
        image='https://scontent.cdninstagram.com/post-owner.jpg'
        self.posts[0]['user']['profile_pic_url']=image
        args=self.args();args.account_id='7';result=scan.snapshot(args)
        self.assertEqual(result['avatar'],image);self.assertEqual(self.webpages,0)
    def test_untrusted_avatar_urls_and_conflicting_open_graph_are_absent(self):
        for url in ['http://scontent.cdninstagram.com/a','https://evil.test/a','https://scontent.cdninstagram.com.evil.test/a','https://user@scontent.cdninstagram.com/a','https://scontent.cdninstagram.com:443/a']:
            self.assertFalse(scan.avatar_url(url))
        self.assertEqual(scan.page_avatar('<meta property="og:image" content="https://scontent.cdninstagram.com/a"><meta property="og:image" content="https://scontent.cdninstagram.com/b">','7','fixture'),'')


if __name__=='__main__':unittest.main()
