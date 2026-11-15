"""Only replace upstream HTTP destinations; run the production parser/download code."""
import json
import os
import runpy
import sys
from pathlib import Path

import requests

root = Path(sys.argv.pop(1))
script = sys.argv.pop(1)
base = (root / "base").read_text()
original_get = requests.Session.get
mode = json.loads((root / "mode.json").read_text())


def get(self, url, **kwargs):
    if url.startswith("https://www.youtube.com/post/"):
        path = "/post"
    elif url.startswith("https://yt3.ggpht.com/image-"):
        path = "/bad-image" if mode.get("bad_second") and url.endswith("-2") else "/photo.jpg"
    else:
        raise AssertionError("Unexpected upstream request")
    cookie = sys.argv[sys.argv.index("--cookies") + 1] if "--cookies" in sys.argv else None
    with (root / "backend.jsonl").open("a") as output:
        output.write(json.dumps({"kind": "post" if path == "/post" else "image", "private_cookie": bool(cookie and os.stat(cookie).st_mode & 0o777 == 0o600), "session": self.cookies.get("SID") == "TEST_ONLY", "proxy": bool(self.proxies)}) + "\n")
    proxies = self.proxies.copy()
    self.proxies.clear()
    try:
        return original_get(self, base + path, **kwargs)
    finally:
        self.proxies.update(proxies)


requests.Session.get = get
sys.argv[0] = script
runpy.run_path(script, run_name="__main__")
