"""Bounded Instagram account search using gallery-dl's authenticated session/API.

Only profile metadata and the public avatar are read; no posts or followers are
enumerated. The parent gives us a private JSON file, never cookie values in argv.
"""

import argparse
from html.parser import HTMLParser
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
    def __init__(self, reason, stage="runtime", http=0):
        self.reason = reason
        self.stage = stage
        self.http = http


def failure(error, stage):
    if isinstance(error, Failure):
        return Failure(error.reason, stage if error.stage == "runtime" else error.stage, error.http)
    if isinstance(error, exception.HttpError):
        code = error.response.status_code if error.response is not None else 0
        if error.response is not None:
            error.response.close()
        reason = "cookies" if code in (401, 403) else "blocked" if code == 429 else "unavailable" if stage == "search" and code == 404 else "notfound" if stage == "profile" and code == 404 else "network"
        return Failure(reason, stage, code)
    if isinstance(error, (exception.AuthenticationError, exception.AuthorizationError)):
        return Failure("cookies", stage)
    if isinstance(error, exception.NotFoundError):
        return Failure("notfound" if stage == "profile" else "unavailable", stage, 404)
    if isinstance(error, RequestException):
        return Failure("network", stage)
    return Failure("format", stage)


def safe_error(error):
    return {"reason": error.reason, "stage": error.stage, "http": error.http}


class ProfileScripts(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=False)
        self.inside = False
        self.parts = []
        self.scripts = []

    def handle_starttag(self, tag, attrs):
        if tag == "script":
            self.inside = True
            self.parts = []

    def handle_data(self, value):
        if self.inside:
            self.parts.append(value)

    def handle_endtag(self, tag):
        if tag == "script" and self.inside:
            self.scripts.append("".join(self.parts))
            self.inside = False


def embedded_profile(page, username):
    """Read a profile's own hydrated data, never a suggested account or post owner."""
    parser = ProfileScripts()
    parser.feed(page)
    for script in parser.scripts:
        try:
            value = json.loads(script)
        except (ValueError, RecursionError):
            continue
        pending = [value]
        visited = 0
        while pending and visited < 100000:
            node = pending.pop()
            visited += 1
            if isinstance(node, dict):
                fields = ("biography", "follower_count", "edge_followed_by", "media_count", "edge_owner_to_timeline_media")
                if (str(node.get("username", "")).lower() == username.lower()
                        and sum(k in node for k in fields) >= 2):
                    try:
                        return complete_profile(node)
                    except Failure:
                        pass
                pending.extend(v for v in node.values() if isinstance(v, (list, dict)))
            elif isinstance(node, list):
                pending.extend(v for v in node if isinstance(v, (list, dict)))
    return None


def complete_profile(user):
    result = account(user)
    result["bio"] = str(user.get("biography") or "")[:600]
    photo = user.get("hd_profile_pic_url_info") or {}
    url = photo.get("url") or user.get("profile_pic_url_hd") or user.get("profile_pic_url") or ""
    result["avatar"] = url if avatar_url(url) else ""
    result["loaded"] = True
    return result


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
            error.response.close()
            try:
                data = self.extr.api._call(
                    "https://www.instagram.com/web/search/topsearch/",
                    params={"context": "user", "query": query, "include_reel": "false"},
                    allow_redirects=False,
                )
            except exception.HttpError as error:
                raise failure(error, "search") from None
        if not isinstance(data, dict) or data.get("status") == "fail":
            raise Failure("cookies", "search")
        if not isinstance(data.get("users"), list):
            raise Failure("format", "search")
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
            raise Failure("format", "search")
        return items

    def exact(self, username):
        if not USERNAME.fullmatch(username):
            raise Failure("unavailable", "search", 404)
        with self.extr.request(self.extr.root + "/" + username + "/", allow_redirects=False, stream=True) as response:
            if 300 <= response.status_code < 400:
                destination = urlsplit(response.headers.get("Location", "")).path
                raise Failure("cookies" if destination.startswith("/accounts/") else "unavailable", "profile", response.status_code)
            chunks, size = [], 0
            for chunk in response.iter_content(65536):
                size += len(chunk)
                if size > 5 * 1024 * 1024:
                    raise Failure("format", "profile")
                chunks.append(chunk)
            page = b"".join(chunks).decode("utf-8", errors="replace")
        item = embedded_profile(page, username)
        if item is not None:
            return item
        # The pinned extractor uses profile_id from this same profile page.
        # Cache it in memory for the current GraphQL document/token discovery.
        match = re.search(r'"profile_id"\s*:\s*"(\d{1,24})"', page)
        if not match:
            raise Failure("unavailable", "profile")
        self.extr.cache_update(self.extr.api._webpage, "/" + username, page, _mem=True)
        item = self.profile({"id": match[1], "username": username})
        if item["username"].lower() != username.lower():
            raise Failure("unavailable", "profile")
        return item

    def profile(self, item):
        # Use the pinned gallery-dl query for public profile fields. No feed or
        # follower enumeration, following action or interactive login is used.
        # gallery-dl discovers the current query document in this profile's
        # scripts; the home feed need not load the profile query at all.
        self.extr._user = {"username": item["username"]}
        data = self.extr.api._call_graphql("PolarisProfilePageContentQuery", {
            "enable_integrity_filters": True, "id": item["id"],
        }, notfound="user")
        user = data.get("user") if isinstance(data, dict) else None
        if not isinstance(user, dict):
            raise Failure("notfound", "profile")
        result = complete_profile(user)
        if result["id"] != item["id"]:
            raise Failure("format", "profile")
        return result

    def enrich(self, items):
        result = list(items[:PAGE])
        warning = None
        for index, item in enumerate(result):
            try:
                result[index] = item if item.get("loaded") else self.profile(item)
            except Exception as error:
                warning = failure(error, "profile")
                # One unavailable suggestion must not erase valid search results.
                # Stop immediately on auth/rate/transport refusal; no burst of retries.
                if warning.reason not in ("notfound", "format"):
                    break
        return result, warning

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
        try:
            items = client.search(request["query"])
        except Exception as error:
            error = failure(error, "search")
            if error.reason != "unavailable" or not USERNAME.fullmatch(request["query"]):
                raise error from None
            try:
                return {"items": [client.exact(request["query"])], "exact": True}
            except Exception as error:
                raise failure(error, "profile") from None
        items[:PAGE], warning = client.enrich(items[:PAGE])
        return {"items": items, **({"warning": safe_error(warning)} if warning else {})}
    if op == "page":
        items, warning = client.enrich(request["items"])
        return {"items": items, **({"warning": safe_error(warning)} if warning else {})}
    if op == "profile":
        item = request["item"]
        try:
            if not item.get("loaded"):
                item = client.profile(item)
        except Exception as error:
            raise failure(error, "profile") from None
        try:
            return {"item": item, "photo": client.photo(item, folder / "instagram-avatar.jpg")}
        except Exception as error:
            raise failure(error, "avatar") from None
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
        result = {"error": error.reason, "stage": error.stage, "http": error.http}
    except Exception as error:
        # Never serialize a response, URL, cookie, proxy or traceback.
        error = failure(error, "runtime")
        result = {"error": error.reason, "stage": error.stage, "http": error.http}
    print(json.dumps(result, ensure_ascii=False))


if __name__ == "__main__":
    main()
