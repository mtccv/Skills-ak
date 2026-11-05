#!/usr/bin/env python3
"""Real local WordPress HTTP security matrix; no external services or production data."""
import argparse
import http.cookiejar
import json
import os
import re
import secrets
import subprocess
import urllib.error
import urllib.parse
import urllib.request


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--base", default="http://localhost:18087")
    parser.add_argument("--container", default="aishqa-security")
    parser.add_argument("--helper", default="/repo/tests/final/security-fixtures.php")
    parser.add_argument("--output", required=True)
    parser.add_argument("--smoke-fixture", help="Read a prior full result and perform only read-only public HTTP regression; no fixture or login changes")
    args = parser.parse_args()
    target = urllib.parse.urlsplit(args.base)
    if target.scheme != "http" or target.hostname not in ("localhost", "127.0.0.1"):
        raise SystemExit("Only the dedicated local HTTP target is allowed")
    environment = os.environ.copy()
    for key in ("DOCKER_HOST", "DOCKER_CONTEXT", "DOCKER_TLS", "DOCKER_TLS_VERIFY", "DOCKER_CERT_PATH"):
        environment.pop(key, None)

    def cli(payload):
        call = subprocess.run(["docker", "--host=unix:///var/run/docker.sock", "exec", "-i", args.container, "php", args.helper],
                              input=json.dumps(payload), text=True, capture_output=True, env=environment, timeout=120)
        if call.returncode:
            raise RuntimeError("QA CLI fixture failed: " + call.stderr[:500])
        return json.loads(call.stdout)

    checks = []
    requests = 0

    def check(name, passed, **evidence):
        checks.append({"name": name, "pass": bool(passed), **evidence})

    def client():
        cookies = http.cookiejar.CookieJar()
        # The target is validated loopback. Disabling an Internet proxy here does
        # not bypass outbound policy and never permits arbitrary URLs.
        opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), urllib.request.HTTPCookieProcessor(cookies), NoRedirect())
        return opener, cookies

    def request(who, path, data=None):
        nonlocal requests
        requests += 1
        req = urllib.request.Request(args.base + path, data=None if data is None else urllib.parse.urlencode(data, doseq=True).encode(),
                                     headers={"User-Agent": "AI-Skills-Hub-Final-QA-Security"})
        try:
            response = who[0].open(req, timeout=30)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            body = response.read(3 * 1024 * 1024).decode("utf-8", "replace")
            headers = dict(response.headers)
            status = response.status
        if re.search(r"Fatal error|<b>(?:Warning|Notice)</b>|WordPress database error", body):
            check("response_has_no_php_or_sql_diagnostic_" + str(requests), False, status=status)
        return status, headers, body

    if args.smoke_fixture:
        with open(args.smoke_fixture, encoding="utf-8") as stream:
            fixture = json.load(stream)["fixture"]
        anonymous = client()
        for language in ("en", "zh"):
            for route in ("", "skills/", "search/?q=QA%20Browser%20%26%20agent", "search/?q=%E4%B8%AD%E6%96%87%20%E6%B5%8B%E8%AF%95%20%2B%20%25"):
                status, _, body = request(anonymous, f"/{language}/{route}")
                check("final_public_route_" + language + "_" + route, status == 200 and "AI Skills Hub" in body, status=status)
            for kind in ("draft", "pending", "rejected", "archived", "stale", "unapproved", "no_reviewer", "no_review_time", "withdrawal"):
                status, _, body = request(anonymous, f"/{language}/skills/{fixture['skills'][kind]}/")
                check("final_private_gate_" + language + "_" + kind, status == 404 and fixture["markers"][kind] not in body, status=status)
            status, _, body = request(anonymous, f"/{language}/skills/{fixture['skills']['published']}/{fixture['run']}-published/")
            check("final_published_detail_" + language, status == 200 and fixture["markers"]["published"] in body, status=status)
        result = {"kind": "read_only_real_http_regression_after_final_seo_changes", "requests": requests, "passed": sum(c["pass"] for c in checks), "total": len(checks), "checks": checks}
        with open(args.output, "w", encoding="utf-8") as stream:
            json.dump(result, stream, ensure_ascii=False, indent=2)
        print(json.dumps({key: result[key] for key in ("requests", "passed", "total")}))
        raise SystemExit(0 if result["passed"] == result["total"] else 1)

    password = secrets.token_urlsafe(32)
    fixture = cli({"mode": "seed", "password": password})
    visitors = {"anonymous": client()}
    for role, user in fixture["users"].items():
        visitors[role] = client()
        request(visitors[role], "/wp-login.php")
        status, _, _ = request(visitors[role], "/wp-login.php", {"log": user["login"], "pwd": password, "testcookie": "1", "wp-submit": "Log In"})
        check("real_login_" + role, status in (302, 303) and any(c.name.startswith("wordpress_logged_in_") for c in visitors[role][1]), status=status)
    password = None
    admin = visitors["administrator"]
    anonymous = visitors["anonymous"]
    actions = {
        "aish_save_skill": {"save_mode": "draft", "translations[en_US][title]": "Unauthorized fixture", "translations[en_US][slug]": "qa-unauthorized"},
        "aish_review": {"id": fixture["skills"]["pending"], "revision": 1, "decision": "approved", "risk": "low", "notes": "QA"},
        "aish_save_term": {"kind": "tags", "name": "Unauthorized QA", "slug": "unauthorized-qa"},
        "aish_save_repository": {"owner": "qa-unauthorized", "name": "fixture"},
        "aish_archive": {"confirmed": "1", "token": "forged-qa-token"},
        "aish_bulk": {"ids[]": str(fixture["skills"]["pending"]), "revisions[" + str(fixture["skills"]["pending"]) + "]": "1", "bulk_action": "draft"},
        "aish_settings": {"per_page": 7, "default_locale": "en_US"},
        "aish_import_action": {"operation": "save", "repository_url": "https://github.com/qa-unauthorized/fixture", "enabled": "0", "interval": "86400"},
        "aish_ai_action": {"operation": "global_pause", "paused": "0"},
        "aish_save_seo": {"route": "home", "locale": "en_US", "title": "Unauthorized QA"},
    }

    def nonces(role, names):
        value = next(c.value for c in visitors[role][1] if c.name.startswith("wordpress_logged_in_"))
        return cli({"mode": "nonce", "user": fixture["users"][role]["id"], "cookie": value, "actions": names})

    before = cli({"mode": "state"})
    for action, fields in actions.items():
        status, _, _ = request(admin, "/wp-admin/admin-post.php", {"action": action, **fields, "_wpnonce": "invalid-final-qa"})
        check("admin_invalid_nonce_" + action, status == 403, status=status)
        status, _, _ = request(anonymous, "/wp-admin/admin-post.php", {"action": action, **fields})
        check("anonymous_mutation_denied_" + action, status in (302, 400, 401, 403), status=status)
    for role in ("subscriber", "contributor", "author", "editor"):
        nonce = nonces(role, list(actions))
        for action, fields in actions.items():
            status, headers, _ = request(visitors[role], "/wp-admin/admin-post.php", {"action": action, **fields, "_wpnonce": nonce[action]})
            # Archive confirmation tokens are user-bound and validated before
            # a mutation capability is reached; a forged token yields the same
            # non-disclosing failure redirect. The full row digests below must
            # also remain unchanged, rather than accepting any arbitrary 303.
            denied = status == 403 or (action == "aish_archive" and status == 303 and "aish_notice=failed" in headers.get("Location", ""))
            check("valid_nonce_without_capability_" + role + "_" + action, denied, status=status)
        for page in ("aish", "aish-skills", "aish-skill-edit", "aish-reviews", "aish-settings", "aish-repositories", "aish-seo", "aish-ai-settings", "aish-ai-generation", "aish-imports"):
            status, _, _ = request(visitors[role], "/wp-admin/admin.php?" + urllib.parse.urlencode({"page": page}))
            check("admin_page_denied_" + role + "_" + page, status == 403, status=status)
    after = cli({"mode": "state"})
    check("all_denied_mutations_preserve_core_rows_and_settings", before == after)
    nonce = nonces("administrator", ["aish_settings"])["aish_settings"]
    status, headers, _ = request(admin, "/wp-admin/admin-post.php", {"action": "aish_settings", "per_page": 7, "default_locale": "en_US", "_wpnonce": nonce})
    check("authorized_valid_nonce_setting_write_positive_control", status == 303 and "aish_notice=saved" in headers.get("Location", "") and cli({"mode": "state"})["admin_settings"] == {"per_page": 7, "default_locale": "en_US"}, status=status)
    status, _, _ = request(admin, "/wp-admin/admin-post.php?action=aish_settings")
    check("state_change_rejects_get", status == 405, status=status)

    private = ("draft", "pending", "rejected", "archived", "stale", "unapproved", "no_reviewer", "no_review_time")
    for kind in private:
        for language in ("en", "zh"):
            status, _, body = request(anonymous, f"/{language}/skills/{fixture['skills'][kind]}/")
            check("public_detail_denies_" + kind + "_" + language, status == 404 and fixture["markers"][kind] not in body, status=status)
            status, _, body = request(anonymous, f"/{language}/search/?" + urllib.parse.urlencode({"q": fixture["markers"][kind]}))
            check("public_search_hides_" + kind + "_" + language, status == 200 and f"/skills/{fixture['skills'][kind]}/" not in body, status=status)
    status, headers, body = request(anonymous, f"/en/skills/{fixture['skills']['partial']}/")
    check("incomplete_english_translation_redirects_to_real_chinese", status == 302 and "/zh/skills/" in headers.get("Location", "") and "translation=missing" in headers.get("Location", "") and fixture["markers"]["partial"] not in body, status=status)
    status, _, body = request(anonymous, f"/zh/skills/{fixture['skills']['partial']}/{fixture['run']}-partial/")
    check("real_chinese_translation_remains_public", status == 200 and fixture["markers"]["partial"] in body, status=status)
    status, _, body = request(anonymous, f"/en/skills/{fixture['skills']['xss']}/{fixture['run']}-xss/")
    check("stored_rich_text_xss_is_removed", status == 200 and "<script>alert" not in body and "onerror=" not in body and "javascript:alert" not in body and "<svg onload" not in body, status=status)
    for name, attack in (("quoted_sql_literal", "' OR 1=1 --"), ("literal_wildcards", "%_"), ("reflected_script", '<script>alert("reflected-qa")</script>'), ("spaces_and_ampersand", "QA Browser & agent"), ("chinese_spaces", "中文 测试 + %")):
        status, _, body = request(anonymous, "/en/search/?" + urllib.parse.urlencode({"q": attack}))
        check("literal_search_and_reflected_output_" + name, status == 200 and '<script>alert("reflected-qa")</script>' not in body and not any(f"/skills/{fixture['skills'][kind]}/" in body for kind in private), status=status)
    for query in ({"sort": "id;DROP TABLE users"}, {"category": "x' OR 1=1 --"}, {"page": "1 OR 1=1"}, {"q[]": "x"}, {"platform": "unprefixed"}):
        status, _, body = request(anonymous, "/en/skills/?" + urllib.parse.urlencode(query))
        check("invalid_query_rejected_" + str(len(checks)), status == 404 and not any(fixture["markers"][kind] in body for kind in private), status=status)
    for status_name in ("draft", "private", "password"):
        status, _, body = request(anonymous, f"/en/tutorials/{fixture['tutorials'][status_name]}/")
        check("private_tutorial_denied_" + status_name, status == 404 and "TUTORIAL_" + status_name not in body, status=status)
    for path in (f"/?p={fixture['inbox']}", f"/?post_type=aish_message&p={fixture['inbox']}", "/wp-json/wp/v2/aish_message", "/wp-json/wp/v2/aish_tutorial", "/wp-json/aish/v1/skills"):
        status, _, body = request(anonymous, path)
        check("private_native_or_unregistered_rest_" + str(len(checks)), status in (301, 302, 400, 404) and fixture["run"] + "_PRIVATE_INBOX" not in body, status=status)
    status, _, body = request(anonymous, "/wp-admin/admin-ajax.php", {"action": "aish_save_skill"})
    check("unregistered_ajax_cannot_mutate", status == 400 and body.strip() == "0", status=status)

    status, _, form = request(anonymous, "/en/contact/")
    matched = re.search(r'name="_wpnonce"[^>]*value="([^"]+)"', form)
    check("public_contact_has_real_nonce", status == 200 and matched is not None, status=status)
    if matched:
        inbox_before = cli({"mode": "state"})["inbox"]
        message = {"action": "aish_public_message", "locale": "en_US", "kind": "contact", "name": "Final QA <img src=x onerror=alert(1)>", "email": "qa@example.invalid", "message": fixture["run"] + "_FORM_PRIVATE_CONTACT", "website": ""}
        status, headers, _ = request(anonymous, "/wp-admin/admin-post.php", message)
        check("public_form_missing_nonce_preserves_inbox", status == 303 and "form=expired" in headers.get("Location", "") and cli({"mode": "state"})["inbox"] == inbox_before, status=status)
        for kind in ("contact", "submit"):
            message.update({"kind": kind, "message": fixture["run"] + "_FORM_PRIVATE_" + kind, "_wpnonce": matched.group(1), "github_url": "https://github.com/qa-fixture/skill" if kind == "submit" else ""})
            status, headers, _ = request(anonymous, "/wp-admin/admin-post.php", message)
            check("public_form_saves_private_" + kind, status == 303 and "form=sent" in headers.get("Location", ""), status=status)
        inbox_after = cli({"mode": "state"})["inbox"]
        new_messages = inbox_after[len(inbox_before):]
        check("contact_submit_create_only_private_inbox_rows", len(new_messages) == 2 and all(row["post_status"] == "private" for row in new_messages))
        for row in new_messages:
            status, _, body = request(anonymous, "/?p=" + str(row["ID"]))
            check("submitted_record_not_public_" + str(row["ID"]), row["post_content"] not in body and status in (301, 302, 404), status=status)
        current = cli({"mode": "state"})
        check("public_submission_does_not_create_skill_or_ai_job", current["skills"] == before["skills"] and current["ai_jobs"] == before["ai_jobs"])

    skill_id = fixture["skills"]["draft"]
    nonce_action = f"aish_preview_{skill_id}_en_US"
    nonce = nonces("administrator", [nonce_action])[nonce_action]
    preview = "/wp-admin/admin-post.php?" + urllib.parse.urlencode({"action": "aish_preview", "id": skill_id, "locale": "en_US", "_wpnonce": nonce})
    status, headers, body = request(admin, preview)
    lower = {k.lower(): v.lower() for k, v in headers.items()}
    check("authorized_private_preview", status == 200 and fixture["markers"]["draft"] in body, status=status)
    check("private_preview_cache_robots_csp", "no-store" in lower.get("cache-control", "") and "noindex" in lower.get("x-robots-tag", "") and "default-src 'none'" in lower.get("content-security-policy", ""))
    for role in ("anonymous", "subscriber"):
        status, _, body = request(visitors[role], preview)
        check("preview_nonce_cannot_bypass_auth_" + role, status in (400, 403) and fixture["markers"]["draft"] not in body, status=status)
    status, _, _ = request(admin, preview.replace(nonce, "invalid-final-qa"))
    check("preview_invalid_nonce_rejected", status == 403, status=status)

    withdrawal_id = fixture["skills"]["withdrawal"]
    path = f"/en/skills/{withdrawal_id}/{fixture['run']}-withdrawal/"
    status, _, _ = request(anonymous, path)
    check("withdrawal_fixture_public_before_archive", status == 200, status=status)
    archived = cli({"mode": "archive", "user": fixture["users"]["administrator"]["id"], "id": withdrawal_id})
    status, _, body = request(anonymous, path)
    check("next_request_archive_invalidates_public_visibility", archived["retained"] and status == 404 and fixture["markers"]["withdrawal"] not in body, status=status)
    probes = cli({"mode": "probes"})
    checks.extend(probes["checks"])
    result = {"kind": "real_wordpress_mysql_and_local_http", "environment": {k: fixture[k] for k in ("site", "wordpress", "php", "mysql")}, "fixture": fixture,
              "requests": requests, "passed": sum(c["pass"] for c in checks), "total": len(checks), "checks": checks,
              "limits": ["HTTP checks do not execute browser JavaScript", "No live GitHub or AI provider requests", "No metadata/private remote-network requests", "No persistent object-cache plugin installed"]}
    with open(args.output, "w", encoding="utf-8") as output:
        json.dump(result, output, ensure_ascii=False, indent=2)
    print(json.dumps({k: result[k] for k in ("requests", "passed", "total")}, ensure_ascii=False))
    raise SystemExit(0 if result["passed"] == result["total"] else 1)


if __name__ == "__main__":
    main()
