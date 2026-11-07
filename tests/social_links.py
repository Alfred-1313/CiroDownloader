"""Offline TikTok redirect validation using the pinned gallery-dl types."""
import runpy
import unittest
from pathlib import Path
from gallery_dl import exception
from gallery_dl.extractor.message import Message

adapter=runpy.run_path(str(Path(__file__).resolve().parents[1]/"bin/tiktok-download.py"),run_name="ciro_tests")


class TikTokLinks(unittest.TestCase):
    def test_direct_posts(self):
        for url in ["https://www.tiktok.com/@fixture/photo/1234567890123456789?photoindex=2","/share/photo/1234567890123456789"]:
            self.assertEqual(adapter["canonical_post"](url).rsplit("/",2)[-2:],["photo","1234567890123456789"])

    def test_short_chain(self):
        class Fake:
            url="https://vm.tiktok.com/AbC123/"
            def request_location(self,url,**kwargs):
                return "https://www.tiktok.com/t/Def456/" if "vm." in url else "https://www.tiktok.com/@fixture/video/1234567890123456789?tracking=1"
        messages=list(adapter["single_short_post"](Fake()))
        self.assertEqual(len(messages),1)
        self.assertEqual(messages[0][0],Message.Queue)
        self.assertEqual(messages[0][1],"https://www.tiktok.com/@fixture/video/1234567890123456789")

    def test_rejected_destinations_and_cycles(self):
        for destination in ["https://www.tiktok.com/@fixture","https://www.tiktok.com/@fixture/live","https://evil.example/@fixture/video/123","https://www.tiktok.com:443/@fixture/video/123","https://tiktok.com@evil.example/@fixture/video/123","https://vm.tiktok.com/AbC123/"]:
            class Fake:
                url="https://vm.tiktok.com/AbC123/"
                calls=0
                def request_location(self,url,**kwargs):
                    self.calls+=1
                    self.assert_calls=self.calls<=4
                    if not self.assert_calls:raise AssertionError("unbounded redirect")
                    return destination
            with self.subTest(destination=destination),self.assertRaises(exception.ExtractionError):
                list(adapter["single_short_post"](Fake()))


if __name__=="__main__":unittest.main(verbosity=2)
