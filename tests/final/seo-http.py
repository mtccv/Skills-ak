#!/usr/bin/env python3
"""Real HTTP SEO checks. Fixture IDs come from seo-seed.php on the disposable local QA site."""
import argparse
import concurrent.futures
import datetime
import hashlib
import json
import re
import sys
import urllib.error
import urllib.parse
import urllib.request
import xml.etree.ElementTree as ET
from html.parser import HTMLParser
from pathlib import Path


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, msg, headers, new_url):
        return None


class Document(HTMLParser):
    def __init__(self, body):
        super().__init__(convert_charrefs=True)
        self.meta = {}
        self.links = []
        self.anchors = []
        self.h1 = 0
        self.title = ""
        self.title_count = 0
        self.in_title = False
        self.language = ""
        self.breadcrumb = False
        self.schemas = []
        self.in_schema = False
        self.schema_text = ""
        self.feed(body)

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == "meta":
            self.meta.setdefault(attrs.get("name", attrs.get("property", "")), []).append(attrs.get("content", ""))
        if tag == "link":
            self.links.append(attrs)
        if tag == "a":
            self.anchors.append(attrs.get("href", ""))
        if tag == "h1":
            self.h1 += 1
        if tag == "html":
            self.language = attrs.get("lang", "")
        if tag == "title":
            self.in_title = True
            self.title_count += 1
        if tag == "nav" and attrs.get("aria-label") in ("Breadcrumbs", "面包屑导航"):
            self.breadcrumb = True
        if tag == "script" and attrs.get("type") == "application/ld+json":
            self.in_schema = True
            self.schema_text = ""

    def handle_endtag(self, tag):
        if tag == "title":
            self.in_title = False
        if tag == "script" and self.in_schema:
            try:
                self.schemas.append(json.loads(self.schema_text))
            except ValueError:
                self.schemas.append({"invalid_json": True})
            self.in_schema = False

    def handle_data(self, text):
        if self.in_title:
            self.title += text
        if self.in_schema:
            self.schema_text += text

    def canonical(self):
        return [item.get("href") for item in self.links if item.get("rel") == "canonical"]

    def alternatives(self):
        return {item["hreflang"]: item.get("href") for item in self.links if item.get("rel") == "alternate" and "hreflang" in item}


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--fixture", required=True)
    parser.add_argument("--output", required=True)
    parser.add_argument("--mode", choices=["none", "yoast", "rankmath", "both", "private", "archived", "withdrawn", "restored", "default-en"], default="none")
    parser.add_argument("--crawl", action="store_true")
    args = parser.parse_args()
    fixture = json.loads(Path(args.fixture).read_text())
    base = fixture["base"].rstrip("/")
    if urllib.parse.urlparse(base).hostname not in ("localhost", "127.0.0.1"):
        raise RuntimeError("Refusing HTTP requests outside the local Final QA site")
    cache = {}
    checks = []
    evidence = {"suite": "real-wordpress-seo-http", "mode": args.mode, "started_utc": datetime.datetime.now(datetime.timezone.utc).isoformat(), "environment": {k: fixture[k] for k in ("base", "wordpress", "php", "mysql")}, "wpml": {"status": "not_executed", "reason": "No licensed WPML package supplied; builtin bilingual routing is not a real WPML test."}, "cases": checks}

    def check(name, condition, actual=None, expected=None):
        checks.append({"name": name, "passed": bool(condition), "actual": actual, "expected": expected})

    def fetch(path):
        url = base + path if path.startswith("/") else path
        if not url.startswith(base + "/"):
            raise RuntimeError("Refusing non-fixture origin: " + url)
        if url in cache:
            return cache[url]
        opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect())
        try:
            response = opener.open(urllib.request.Request(url, headers={"User-Agent": "AI-Skills-Hub-Final-QA/1.0"}), timeout=25)
        except urllib.error.HTTPError as error:
            response = error
        body = response.read(5 * 1024 * 1024).decode("utf-8", "replace")
        result = {"url": url, "status": response.code, "headers": {k.lower(): v for k, v in response.headers.items()}, "body": body}
        result["document"] = Document(body)
        cache[url] = result
        return result

    def noindex(response):
        return "noindex" in response["headers"].get("x-robots-tag", "") or any("noindex" in value for value in response["document"].meta.get("robots", []))

    def page(path, indexable=True, canonical=None, locale=None, status=200):
        response = fetch(path)
        doc = response["document"]
        check(response["url"] + " status", response["status"] == status, response["status"], status)
        check(response["url"] + " index policy", noindex(response) != indexable, noindex(response), not indexable)
        check(response["url"] + " no-store", "no-store" in response["headers"].get("cache-control", ""), response["headers"].get("cache-control"))
        if status == 200:
            expected = canonical or response["url"]
            check(response["url"] + " single self canonical", doc.canonical() == [expected], doc.canonical(), [expected])
            check(response["url"] + " single H1", doc.h1 == 1, doc.h1, 1)
            check(response["url"] + " single nonempty document title", doc.title_count == 1 and bool(doc.title.strip()), {"count": doc.title_count, "title": doc.title})
            if indexable:
                check(response["url"] + " single valid JSON-LD graph", len(doc.schemas) == 1 and isinstance(doc.schemas[0].get("@graph"), list), doc.schemas)
                nodes = doc.schemas[0].get("@graph", []) if doc.schemas else []
                webpages = [node for node in nodes if node.get("@type") == "WebPage"]
                websites = [node for node in nodes if node.get("@type") == "WebSite"]
                crumbs = [node for node in nodes if node.get("@type") == "BreadcrumbList"]
                check(response["url"] + " schema self canonical and language", len(webpages) == 1 and webpages[0].get("url") == expected and webpages[0].get("inLanguage") in (doc.language, "en" if doc.language == "en-US" else doc.language), webpages)
                check(response["url"] + " schema website same language", len(websites) == 1 and websites[0].get("inLanguage") in (doc.language, "en" if doc.language == "en-US" else doc.language), websites)
                check(response["url"] + " schema no invented ratings or safety", not any(key in node for node in nodes for key in ("aggregateRating", "review", "author", "award")), nodes)
                if doc.breadcrumb:
                    check(response["url"] + " schema breadcrumb real current URL", len(crumbs) == 1 and crumbs[0]["itemListElement"][-1]["item"] == expected, crumbs)
            else:
                check(response["url"] + " excluded pages have no structured data", not doc.schemas, doc.schemas)
            if locale:
                allowed = ("en", "en-US") if locale == "en-US" else (locale,)
                check(response["url"] + " html language", doc.language in allowed, doc.language, allowed)
            alternate_nodes = [item for item in doc.links if item.get("rel") == "alternate" and "hreflang" in item]
            check(response["url"] + " no duplicate hreflang", len(alternate_nodes) == len(doc.alternatives()), len(alternate_nodes))
            for key in ("description", "og:title", "og:description", "og:url", "twitter:card", "twitter:title", "twitter:description"):
                check(response["url"] + " single " + key, len(doc.meta.get(key, [])) == 1, doc.meta.get(key, []))
        else:
            check(response["url"] + " no error structured data", not doc.schemas, doc.schemas)
            check(response["url"] + " no error canonical/hreflang", not doc.canonical() and not doc.alternatives(), {"canonical": doc.canonical(), "alternates": doc.alternatives()})
        check(response["url"] + " no private fixture body", not any(marker in response["body"] for marker in fixture["hidden_markers"]))
        return response

    def urls_from_xml(response):
        try:
            tree = ET.fromstring(response["body"])
        except ET.ParseError:
            return [], None
        return [item.text for item in tree.findall(".//{http://www.sitemaps.org/schemas/sitemap/0.9}loc")], tree

    def check_owner_projection():
        children, tree = urls_from_xml(fetch("/aish-sitemap.xml"))
        owner = fetch("/sitemap_index.xml")
        if owner["status"] != 200:
            owner = fetch("/wp-sitemap.xml")
        owner_urls, owner_tree = urls_from_xml(owner)
        projected = [url for url in owner_urls if "/aish-sitemap-" in url]
        check("owner index follows current bilingual chunks without flushing cache", owner["status"] == 200 and owner_tree is not None and sorted(projected) == sorted(children), {"owner": projected, "current": children})

    pair = fixture["skills"]["pair"]
    if args.mode == "private":
        page("/en/", indexable=False, locale="en-US")
        sitemap = fetch("/aish-sitemap.xml")
        check("private site disables XML", sitemap["status"] == 404, sitemap["status"], 404)
        robots = fetch("/robots.txt")
        for path in ("/sitemap_index.xml", "/wp-sitemap.xml"):
            owner = fetch(path)
            check("private site owner does not advertise Hub children " + path, "/aish-sitemap-" not in owner["body"])
        check("private site robots disallows all", "Disallow: /\n" in robots["body"] and "Sitemap:" not in robots["body"], robots["body"])
    elif args.mode in ("archived", "withdrawn"):
        for url in pair["urls"].values():
            response = page(url, indexable=False, status=404)
            check("withdrawn edit never leaks", "QA_SEO_UNREVIEWED_EDIT" not in response["body"])
        for lang in ("zh", "en"):
            response = fetch(f"/aish-sitemap-skills-{lang}-1.xml")
            check("archived pair immediately absent " + lang, not any(url in response["body"] for url in pair["urls"].values()))
        response = fetch("/en/skills/?q=pair")
        check("archived pair absent from search", "QA_SEO_BODY_pair" not in response["body"] and pair["urls"]["en_US"] not in response["body"])
        check_owner_projection()
    elif args.mode == "restored":
        for locale, url in pair["urls"].items():
            response = page(url, locale="zh-CN" if locale == "zh_CN" else "en-US")
            check("restored hreflang immediately reflects both languages " + locale, response["document"].alternatives().get("zh-CN") == pair["urls"]["zh_CN"] and response["document"].alternatives().get("en") == pair["urls"]["en_US"])
        for lang, locale in (("zh", "zh_CN"), ("en", "en_US")):
            response = fetch(f"/aish-sitemap-skills-{lang}-1.xml")
            check("restored pair immediately present " + lang, pair["urls"][locale] in response["body"])
        check_owner_projection()
    elif args.mode == "default-en":
        root = fetch("/")
        check("default English redirect", root["status"] == 302 and root["headers"].get("location") == base + "/en/", root["headers"].get("location"))
        doc = page(pair["urls"]["zh_CN"], locale="zh-CN")["document"]
        check("x-default tracks English", doc.alternatives().get("x-default") == pair["urls"]["en_US"], doc.alternatives())
    else:
        root = fetch("/")
        check("default Chinese redirect", root["status"] == 302 and root["headers"].get("location") == base + "/zh/", root["headers"].get("location"))
        for lang, locale in (("zh", "zh-CN"), ("en", "en-US")):
            for route in ("", "skills/", "tutorials/", "category/qa-development/", "tag/qa-cli/", "about/"):
                page(f"/{lang}/{route}", locale=locale)
            response = page(pair["urls"]["zh_CN" if lang == "zh" else "en_US"], locale=locale)
            expected = {"zh-CN": pair["urls"]["zh_CN"], "en": pair["urls"]["en_US"], "x-default": pair["urls"]["zh_CN"]}
            check(lang + " pair hreflang", response["document"].alternatives() == expected, response["document"].alternatives(), expected)
            check(lang + " actual translated navigation", all(url in response["document"].anchors for url in pair["urls"].values()))
            check(lang + " visible breadcrumb", response["document"].breadcrumb)
            check(lang + " reviewed SEO title", ("中文 SEO pair" if lang == "zh" else "English SEO pair") in response["document"].title, response["document"].title)
            check(lang + " private review note absent", "QA_SEO_PRIVATE_REVIEW" not in response["body"])
            tutorial = fixture["tutorials"]["pair"]["zh_CN" if lang == "zh" else "en_US"]
            response = page(tutorial["url"], locale=locale)
            check(lang + " tutorial translated IDs", response["document"].alternatives().get("en") == fixture["tutorials"]["pair"]["en_US"]["url"] and response["document"].alternatives().get("zh-CN") == fixture["tutorials"]["pair"]["zh_CN"]["url"], response["document"].alternatives())
            page(f"/{lang}/submit/", indexable=False, locale=locale)
        en_pair = fetch(pair["urls"]["en_US"])["document"]
        check("configured OG image", en_pair.meta.get("og:image") == ["https://example.test/qa-share.png"], en_pair.meta.get("og:image"))
        check("configured Twitter image card", en_pair.meta.get("twitter:card") == ["summary_large_image"])
        wrong_slug = fetch(f"/en/skills/{pair['id']}/old-slug/")
        check("wrong slug 301 to same language", wrong_slug["status"] == 301 and wrong_slug["headers"].get("location") == pair["urls"]["en_US"], {"status": wrong_slug["status"], "location": wrong_slug["headers"].get("location")})
        english = fixture["skills"]["english-only"]
        response = page(english["urls"]["en_US"], locale="en-US")
        check("missing translation not advertised", response["document"].alternatives() == {"en": english["urls"]["en_US"], "x-default": english["urls"]["en_US"]}, response["document"].alternatives())
        check("missing translation switch uses target language list", base + "/zh/skills/?translation=missing" in response["document"].anchors)
        response = fetch(f"/zh/skills/{english['id']}/qa-english-only-zh/")
        check("missing Skill translation redirects to real English", response["status"] == 302 and response["headers"].get("location") == english["urls"]["en_US"] + "?translation=missing", response["headers"].get("location"))
        tutorial = fixture["tutorials"]["english-only"]
        response = fetch(f"/zh/tutorials/{tutorial['id']}/")
        check("missing tutorial translation redirects to real English", response["status"] == 302 and response["headers"].get("location") == tutorial["url"] + "?translation=missing", response["headers"].get("location"))
        page("/en/contact/", locale="en-US")
        response = page("/zh/contact/", indexable=False, locale="zh-CN")
        check("missing static translation has no hreflang", not response["document"].alternatives())
        for suffix in ("?q=zz-no-match", "?sort=title", "?category=qa-development&tag=qa-cli&platform=platform-qa-codex", "?unknown=value"):
            path = "/en/skills/" + suffix
            canonical = base + "/en/skills/" if suffix.startswith("?unknown=") else None
            page(path, indexable=False, canonical=canonical, locale="en-US")
        response = page("/en/skills/?page=2", locale="en-US")
        check("pagination has distinct title and no guessed alternates", "Page 2" in response["document"].title and not response["document"].alternatives())
        page("/en/search/?q=pair", indexable=False, locale="en-US")
        for value in ("QA Browser", "QA Browser & agent", "中文 测试 + %", "alpha&sort=oldest", "#fragment", "C++", "a=b?c/d"):
            path = "/en/search/?q=" + urllib.parse.quote(value, safe="")
            response = page(path, indexable=False, locale="en-US")
            query = urllib.parse.parse_qs(urllib.parse.urlparse(response["document"].canonical()[0]).query) if response["document"].canonical() else {}
            check("search round trip " + value, query == {"q": [value]}, query)
            translated = [link for link in response["document"].anchors if link.startswith(base + "/zh/search/?")]
            check("search language link round trip " + value, bool(translated) and all(urllib.parse.parse_qs(urllib.parse.urlparse(link).query) == {"q": [value]} for link in translated), translated)
        page("/en/contact/?form=sent", indexable=False, canonical=base + "/en/contact/", locale="en-US")
        page("/en/not-found/", indexable=False, status=404)
        for name in ("draft", "pending", "rejected", "archived", "stale-review"):
            for url in fixture["skills"][name]["urls"].values():
                page(url, indexable=False, status=404)
        for name in ("draft", "private", "password"):
            page(fixture["tutorials"][name]["url"], indexable=False, status=404)
        for url in fixture["skills"]["noindex"]["urls"].values():
            page(url, indexable=False)
        page(fixture["tutorials"]["noindex"]["url"], indexable=False)
        index = fetch("/aish-sitemap.xml")
        children, tree = urls_from_xml(index)
        check("Hub XML index status/type", index["status"] == 200 and tree is not None and "sitemapindex" in tree.tag, index["status"])
        check("200 URL boundary creates English second chunk", base + "/aish-sitemap-skills-en-2.xml" in children, children)
        check("XML children unique", len(children) == len(set(children)))
        all_urls = []
        for url in children:
            response = fetch(url)
            urls, tree = urls_from_xml(response)
            check(url + " complete valid XML", response["status"] == 200 and tree is not None and "urlset" in tree.tag, response["status"])
            check(url + " bounded entries", 0 < len(urls) <= 200, len(urls))
            check(url + " no-store", "no-store" in response["headers"].get("cache-control", ""))
            if tree is not None:
                for index, date in enumerate(tree.findall(".//{http://www.sitemaps.org/schemas/sitemap/0.9}lastmod")):
                    check(url + " persisted UTC lastmod " + str(index), bool(re.fullmatch(r"\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z", date.text or "")), date.text)
            all_urls.extend(urls)
        check("Sitemap URLs globally unique", len(all_urls) == len(set(all_urls)), len(all_urls))
        excluded = [url for name in ("draft", "pending", "rejected", "archived", "stale-review", "noindex") for url in fixture["skills"][name]["urls"].values()]
        excluded += [fixture["tutorials"][name]["url"] for name in ("draft", "private", "password", "noindex")]
        excluded += [base + "/zh/contact/", base + "/en/submit/", base + "/zh/submit/"]
        check("Sitemap excludes all private/missing/noindex fixtures", not set(excluded).intersection(all_urls), sorted(set(excluded).intersection(all_urls)))
        check("Sitemap excludes search/filter/pagination", not any("?" in url or "/search/" in url for url in all_urls))
        check("Sitemap contains real Skill pair", all(url in all_urls for url in pair["urls"].values()))
        page_native_urls = [page["native_url"] for translations in fixture["pages"].values() for page in translations.values()]
        owner = fetch("/wp-sitemap.xml" if args.mode == "none" else "/sitemap_index.xml")
        owner_urls, owner_tree = urls_from_xml(owner)
        check("active owner index is XML", owner["status"] == 200 and owner_tree is not None, {"status": owner["status"], "location": owner["headers"].get("location")})
        check("active owner advertises every Hub child exactly once", all(owner_urls.count(url) == 1 for url in children), {"hub_children": len(children), "advertised": sum(owner_urls.count(url) for url in children)})
        for child in owner_urls:
            if "aish-sitemap" in child:
                continue
            response = fetch(child)
            check(child + " no duplicate managed native pages", not any(url in response["body"] for url in page_native_urls))
        if args.crawl:
            with concurrent.futures.ThreadPoolExecutor(max_workers=4) as executor:
                list(executor.map(fetch, all_urls))
            for url in all_urls:
                response = cache[url]
                check(url + " crawl indexable self canonical", response["status"] == 200 and not noindex(response) and response["document"].canonical() == [url], {"status": response["status"], "noindex": noindex(response), "canonical": response["document"].canonical()})
        evidence["xml"] = {"child_count": len(children), "url_count": len(all_urls), "crawled_all_urls": args.crawl}
        robots = fetch("/robots.txt")
        check("robots actual HTTP response", robots["status"] == 200, robots["status"])
        check("robots retains admin and ajax policy", "Disallow: /wp-admin/" in robots["body"] and "Allow: /wp-admin/admin-ajax.php" in robots["body"], robots["body"])
        check("robots advertises sitemap without blocking query noindex", "Sitemap:" in robots["body"] and not re.search(r"Disallow:.*(?:\?|/search/)", robots["body"]), robots["body"])
        evidence["schema_observation"] = {"home": fetch("/en/")["document"].schemas, "skill": en_pair.schemas}
    evidence["requests"] = [{"url": r["url"], "status": r["status"], "body_sha256": hashlib.sha256(r["body"].encode()).hexdigest(), "headers": {key: value for key, value in r["headers"].items() if key in ("content-type", "cache-control", "x-robots-tag", "location")}} for r in cache.values()]
    evidence["counts"] = {"cases": len(checks), "passed": sum(item["passed"] for item in checks), "failed": sum(not item["passed"] for item in checks), "http_requests": len(cache)}
    Path(args.output).write_text(json.dumps(evidence, ensure_ascii=False, indent=2) + "\n")
    print(json.dumps({"mode": args.mode, **evidence["counts"], "evidence": args.output}, ensure_ascii=False))
    for item in checks:
        if not item["passed"]:
            print(json.dumps(item, ensure_ascii=False))
    return 1 if evidence["counts"]["failed"] else 0


if __name__ == "__main__":
    sys.exit(main())
