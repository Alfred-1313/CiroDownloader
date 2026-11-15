"""Bounded Instagram account search using gallery-dl's authenticated session/API.

Only profile metadata and the public avatar are read; no posts or followers are
enumerated. The parent gives us a private JSON file, never cookie values in argv.
"""

import argparse
import json
import logging
import re
import sys
from pathlib import Path
from urllib.parse import urlsplit

from gallery_dl import config, exception, extractor
from requests.exceptions import RequestException

LIMIT = 12
PAGE = 6
USERNAME = re.compile(r"[A-Za-z0-9_][A-Za-z0-9_.]{0,29}\Z")


class Failure(Exception):
    def __init__(self, reason):
        self.reason = reason


def count(value):
    if isinstance(value, dict):
        value = value.get("count")
    if isinstance(value, bool):
        return None
    try:
        number = int(value)
        return number if 0 <= number <= 10**13 else None
    except (TypeError, ValueError, OverflowError):
        return None


def account(user):
    if not isinstance(user, dict):
        raise Failure("format")
    username = user.get("username", "")
    if not isinstance(username, str) or not USERNAME.fullmatch(username):
        raise Failure("format")
    pk = str(user.get("pk") or user.get("id") or "")
    if not re.fullmatch(r"\d{1,24}", pk):
        raise Failure("format")
    return {
        "id": pk,
        "username": username,
        "name": str(user.get("full_name") or "")[:100],
        "posts": count(user.get("media_count", user.get("edge_owner_to_timeline_media"))),
        "followers": count(user.get("follower_count", user.get("edge_followed_by"))),
        "following": count(user.get("following_count", user.get("edge_follow"))),
        "private": bool(user.get("is_private")),
    }


def avatar_url(url):
    try:
        parts = urlsplit(url)
        host = (parts.hostname or "").lower()
        return (
            parts.scheme == "https" and not parts.username and not parts.password
            and parts.port in (None, 443)
            and any(host.endswith("." + domain) for domain in ("cdninstagram.com", "fbcdn.net"))
        )
    except (ValueError, TypeError):
        return False


class Client:
    def __init__(self, extr):
        self.extr = extr

    def search(self, query):
        # The current web route. Only a missing route permits the older route;
        # login challenges/rate limits must not become an empty result list.
        try:
            data = self.extr.api._call("/v1/web/search/topsearch/", params={
                "context": "user", "query": query, "include_reel": "false",
            }, allow_redirects=False)
        except exception.HttpError as error:
            if error.response is None or error.response.status_code != 404:
                raise
            data = self.extr.api._call(
                "https://www.instagram.com/web/search/topsearch/",
                params={"context": "user", "query": query, "include_reel": "false"},
                allow_redirects=False,
            )
        if not isinstance(data, dict) or data.get("status") == "fail":
            raise Failure("cookies")
        if not isinstance(data.get("users"), list):
            raise Failure("format")
        items, seen = [], set()
        for entry in data["users"]:
            try:
                item = account(entry.get("user", entry))
            except (Failure, AttributeError):
                continue
            if item["id"] in seen or item["username"].lower() in seen:
                continue
            seen.update((item["id"], item["username"].lower()))
            items.append(item)
            if len(items) == LIMIT:
                break
        if data["users"] and not items:
            raise Failure("format")
        return items

    def profile(self, item):
        # Use the pinned gallery-dl profile query instead of walking the feed or
        # relying on the retired web_profile_info endpoint. Private accounts only
        # expose their public profile fields; no following or login action.
        # gallery-dl discovers the current query document in this profile's
        # scripts; the home feed need not load the profile query at all.
        self.extr._user = {"username": item["username"]}
        data = self.extr.api._call_graphql("PolarisProfilePageContentQuery", {
            "enable_integrity_filters": True, "id": item["id"],
        }, notfound="user")
        user = data.get("user") if isinstance(data, dict) else None
        if not isinstance(user, dict):
            raise Failure("notfound")
        result = account(user)
        if result["id"] != item["id"]:
            raise Failure("format")
        result["bio"] = str(user.get("biography") or "")[:600]
        photo = user.get("hd_profile_pic_url_info") or {}
        url = photo.get("url") or user.get("profile_pic_url_hd") or user.get("profile_pic_url") or ""
        result["avatar"] = url if avatar_url(url) else ""
        result["loaded"] = True
        return result

    def enrich(self, items):
        result = []
        for item in items[:PAGE]:
            result.append(item if item.get("loaded") else self.profile(item))
        return result

    def photo(self, item, destination):
        url = item.get("avatar", "")
        if not avatar_url(url):
            return False
        # No redirects: an external URL can never receive the session cookies.
        with self.extr.request(url, stream=True, allow_redirects=False) as response:
            if response.status_code != 200:
                raise Failure("network")
            total, head = 0, b""
            with open(destination, "wb") as output:
                for chunk in response.iter_content(65536):
                    total += len(chunk)
                    if total > 8 * 1024 * 1024:
                        raise Failure("format")
                    if len(head) < 16:
                        head += chunk[:16 - len(head)]
                    output.write(chunk)
            if not total or not (head.startswith(b"\xff\xd8\xff") or head.startswith(b"\x89PNG\r\n\x1a\n")):
                raise Failure("format")
        Path(destination).chmod(0o600)
        return True


def run(client, request, folder):
    op = request.get("op")
    if op == "search":
        items = client.search(request["query"])
        items[:PAGE] = client.enrich(items[:PAGE])
        return {"items": items}
    if op == "page":
        return {"items": client.enrich(request["items"])}
    if op == "profile":
        item = request["item"]
        if not item.get("loaded"):
            item = client.profile(item)
        return {"item": item, "photo": client.photo(item, folder / "instagram-avatar.jpg")}
    raise Failure("format")


def main():
    logging.disable(logging.CRITICAL)
    parser = argparse.ArgumentParser()
    parser.add_argument("--request", required=True)
    parser.add_argument("--cookies", required=True)
    parser.add_argument("--proxy", default="")
    args = parser.parse_args()
    path = Path(args.request)
    try:
        if path.stat().st_size > 65536:
            raise Failure("format")
        request = json.loads(path.read_text())
        config.set(("cache",), "file", ":memory:")
        config.set(("extractor", "instagram"), "cookies", args.cookies)
        config.set(("extractor", "instagram"), "user-cache", "memory")
        config.set(("extractor", "instagram"), "retries", 0)
        config.set(("extractor", "instagram"), "timeout", (8, 12))
        config.set(("extractor", "instagram"), "sleep-request", 1.5)
        if args.proxy:
            config.set(("extractor", "instagram"), "proxy", args.proxy)
        extr = extractor.find("https://www.instagram.com/instagram/info")
        extr.initialize()
        extr.login()
        if not extr.cookies_check(("sessionid",)):
            raise Failure("cookies")
        result = run(Client(extr), request, path.parent)
    except Failure as error:
        result = {"error": error.reason}
    except exception.HttpError as error:
        code = error.response.status_code if error.response is not None else 0
        result = {"error": "cookies" if code in (401, 403) else "blocked" if code == 429 else "notfound" if code == 404 else "network"}
    except exception.NotFoundError:
        result = {"error": "notfound"}
    except (exception.AuthenticationError, exception.AuthorizationError):
        result = {"error": "cookies"}
    except RequestException:
        result = {"error": "network"}
    except Exception:
        # Never serialize a response, URL, cookie, proxy or traceback.
        result = {"error": "format"}
    print(json.dumps(result, ensure_ascii=False))


if __name__ == "__main__":
    main()
