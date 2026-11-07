"""Validate share redirects without network access or real session cookies."""

import runpy
import unittest
from pathlib import Path

from gallery_dl import exception

adapter = runpy.run_path(str(Path(__file__).resolve().parents[1] / "bin/pinterest-download.py"), run_name="ciro_tests")


class PinterestRedirectTests(unittest.TestCase):
    def test_shared_pins(self):
        for url in (
            "https://www.pinterest.com/pin/123456789/sent/?invite_code=fixture&sender=fixture",
            "https://www.pinterest.com/pin/123456789/sent",
            "https://pinterest.co.uk/pin/title--123456789/sent/",
            "https://br.pinterest.com/pin/123456789/",
            "/pin/123456789/sent/?tracking=fixture",
            "//www.pinterest.com/pin/123456789/sent/",
        ):
            with self.subTest(url=url):
                self.assertEqual(adapter["canonical_pin"](url), "https://www.pinterest.com/pin/123456789/")

    def test_non_pin_destinations(self):
        for url in (
            "https://www.pinterest.com/user/board/",
            "https://www.pinterest.com/pin/123456789/related/",
            "https://www.pinterest.com/pin/123456789/sent/extra/",
            "https://pinterest.evil/pin/123456789/sent/",
            "//evil.example/pin/123456789/sent/",
            "https://pinterest.com@evil.example/pin/123456789/sent/",
            "https://www.pinterest.com:443/pin/123456789/sent/",
            "file:///pin/123456789/sent/",
            "pinterest://pin/123456789",
        ):
            with self.subTest(url=url):
                with self.assertRaises(exception.ExtractionError):
                    adapter["canonical_pin"](url)


if __name__ == "__main__":
    unittest.main(verbosity=2)
