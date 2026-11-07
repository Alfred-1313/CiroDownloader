"""The TikTok wrapper lists H.264 streams before HEVC ones, so videos are remuxed, not re-encoded."""
import importlib.util
import pathlib
import sys
import unittest

from gallery_dl import config

PATH = pathlib.Path(__file__).resolve().parents[1] / "bin" / "tiktok-download.py"
spec = importlib.util.spec_from_file_location("tiktok_download", PATH)
module = importlib.util.module_from_spec(spec)
sys.modules["tiktok_download"] = module
spec.loader.exec_module(module)


def entry(codec, size, name):
    return {"CodecType": codec, "PlayAddr": {"Width": size[0], "Height": size[1], "UrlList": [name + "-a", name + "-b"]}}


def fake_original(self, video):
    # Same contract as gallery-dl: largest picture first, then the root playAddr.
    info = video.get("bitrateInfo") or []
    info = info if isinstance(info, list) else [info]
    ordered = sorted(info, key=lambda e: e["PlayAddr"]["Width"] * e["PlayAddr"]["Height"], reverse=True)
    urls = [url for e in ordered for url in e["PlayAddr"]["UrlList"]]
    if "playAddr" in video:
        urls.append(video["playAddr"])
    return urls


class PreferH264(unittest.TestCase):
    def setUp(self):
        module.original_video_urls = fake_original
        config.clear()

    def test_h264_comes_first_even_when_hevc_is_larger(self):
        video = {"bitrateInfo": [entry("h265_hvc1", (1080, 1920), "hevc1080"), entry("h264", (576, 1024), "h264540"), entry("h265_hvc1", (720, 1280), "hevc720")],
                 "playAddr": "root"}
        urls = module.h264_first(None, video)
        self.assertEqual(urls[:2], ["h264540-a", "h264540-b"])
        self.assertIn("hevc1080-a", urls)
        self.assertLess(urls.index("h264540-a"), urls.index("hevc1080-a"))
        self.assertEqual(len(urls), len(set(urls)))

    def test_hevc_stays_available_as_a_fallback_and_is_used_alone_when_needed(self):
        video = {"bitrateInfo": [entry("h265_hvc1", (1080, 1920), "hevc1080")]}
        self.assertEqual(module.h264_first(None, video), ["hevc1080-a", "hevc1080-b"])

    def test_without_bitrate_information_nothing_changes(self):
        self.assertEqual(module.h264_first(None, {"playAddr": "root"}), ["root"])

    def test_the_preference_can_be_switched_off(self):
        video = {"bitrateInfo": [entry("h265_hvc1", (1080, 1920), "hevc1080"), entry("h264", (576, 1024), "h264540")]}
        config.set(("extractor", "tiktok"), "prefer-h264", False)
        self.assertEqual(module.h264_first(None, video)[0], "hevc1080-a")


if __name__ == "__main__":
    unittest.main()
