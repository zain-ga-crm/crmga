#!/usr/bin/env python3
# =============================================================================
#  SuiteCRM 8  V8 REST API  READ-ONLY audit   (Gunness & Associates / crmga.com)
#  GET-only against /Api/V8 (+ one OAuth token POST). Makes NO changes.
#  Stdlib only (urllib) - runs anywhere python3 exists, no pip installs.
#
#  It uses the SAME client_credentials your n8n workflows use. Find them in
#  n8n -> any workflow -> the "SuiteCRM Token" HTTP node (client_id / client_secret).
#
#  RUN (from your server or laptop - anywhere with internet to crmga.com):
#     export CLIENT_ID='...'          # from the SuiteCRM Token node
#     export CLIENT_SECRET='...'      # from the SuiteCRM Token node
#     python3 crm_api_audit.py > crm_api_report.txt 2>&1
#  then upload crm_api_report.txt back to the chat.
# =============================================================================
import os, sys, json, time, urllib.request, urllib.parse, urllib.error

BASE    = os.environ.get("CRM_BASE", "https://crmga.com/public").rstrip("/")
CID     = os.environ.get("CLIENT_ID", "")
CSEC    = os.environ.get("CLIENT_SECRET", "")
TIMEOUT = int(os.environ.get("CRM_TIMEOUT", "60"))


def http(method, url, headers=None, data=None):
    req = urllib.request.Request(url, data=data, method=method, headers=headers or {})
    with urllib.request.urlopen(req, timeout=TIMEOUT) as r:
        raw = r.read().decode("utf-8", "replace")
    try:
        return json.loads(raw)
    except Exception:
        return {"_raw": raw[:2000]}


def get_token():
    body = urllib.parse.urlencode(
        {"grant_type": "client_credentials", "client_id": CID, "client_secret": CSEC}
    ).encode()
    resp = http("POST", BASE + "/Api/access_token",
                {"Content-Type": "application/x-www-form-urlencoded"}, body)
    return resp.get("access_token") if isinstance(resp, dict) else None


def api_get(path, token, qs=None):
    url = BASE + "/Api/V8" + path
    if qs:
        url += "?" + urllib.parse.urlencode(qs, safe="[]")
    H = {"Authorization": "Bearer " + token,
         "Accept": "application/vnd.api+json",
         "Content-Type": "application/vnd.api+json"}
    return http("GET", url, H)


def module_names(meta_modules):
    """V8 /meta/modules can return data as a list or an object; handle both."""
    names = []
    if isinstance(meta_modules, dict):
        d = meta_modules.get("data")
        if isinstance(d, list):
            for x in d:
                if isinstance(x, dict):
                    names.append(x.get("id") or x.get("type")
                                 or (x.get("attributes") or {}).get("name"))
        elif isinstance(d, dict):
            names = list(d.keys())
        elif isinstance(meta_modules.get("meta"), dict):
            names = list(meta_modules["meta"].keys())
    return sorted({n for n in names if n})


def count_from_meta(resp):
    """Derive record count. SuiteCRM returns it in meta; try known keys."""
    if isinstance(resp, dict):
        meta = resp.get("meta")
        if isinstance(meta, dict):
            for k in ("total-pages", "total_pages", "total-records",
                      "total_records", "total", "records", "count"):
                if k in meta:
                    try:
                        return int(meta[k]), k
                    except Exception:
                        pass
        data = resp.get("data")
        if isinstance(data, list):
            return len(data), "data_len(floor)"
    return None, None


def main():
    if not CID or not CSEC:
        print("ERROR: set CLIENT_ID and CLIENT_SECRET env vars first "
              "(copy them from your n8n 'SuiteCRM Token' node).")
        return 2
    try:
        token = get_token()
    except urllib.error.HTTPError as e:
        print("ERROR getting token: HTTP %s - %s" % (e.code, e.reason)); return 2
    except Exception as e:
        print("ERROR getting token: %s" % e); return 2
    if not token:
        print("ERROR: no access_token returned (bad creds or base URL?)."); return 2

    print("=" * 70)
    print("SuiteCRM V8 API READ-ONLY audit   base=%s" % BASE)
    print("=" * 70)

    mods = api_get("/meta/modules", token)
    names = module_names(mods)
    print("\n[1] MODULES DISCOVERED: %d" % len(names))
    if not names:
        print("    Could not parse module list. Raw /meta/modules top keys: %s"
              % (list(mods.keys()) if isinstance(mods, dict) else type(mods)))
        print(json.dumps(mods, indent=2)[:3000]); return 1
    print("    " + ", ".join(names))

    results, first_meta = [], None
    for m in names:
        try:
            resp = api_get("/module/" + urllib.parse.quote(m), token,
                           {"page[size]": 1, "page[number]": 1})
            cnt, via = count_from_meta(resp)
            if first_meta is None and isinstance(resp, dict):
                first_meta = {"module": m, "top_keys": list(resp.keys()),
                              "meta": resp.get("meta")}
            results.append({"module": m, "count": cnt, "via": via})
        except urllib.error.HTTPError as e:
            results.append({"module": m, "count": None, "error": "HTTP %s" % e.code})
        except Exception as e:
            results.append({"module": m, "count": None, "error": str(e)[:120]})
        time.sleep(0.05)

    print("\n[2] COUNT-FIELD VERIFICATION (first module response meta):")
    print(json.dumps(first_meta, indent=2))

    with_data = sorted([r for r in results if (r.get("count") or 0) > 0],
                       key=lambda r: r["count"], reverse=True)
    print("\n[3] MODULES WITH DATA: %d" % len(with_data))
    print("    %-34s %10s   %s" % ("MODULE", "COUNT", "via"))
    for r in with_data:
        print("    %-34s %10d   %s" % (r["module"], r["count"], r.get("via")))

    errs = [r for r in results if r.get("error")]
    if errs:
        print("\n[3b] Modules not countable (%d): %s"
              % (len(errs), ", ".join("%s(%s)" % (r["module"], r["error"]) for r in errs)))

    print("\n[4] FIELDS PER MODULE WITH DATA:")
    for r in with_data:
        m = r["module"]
        try:
            f = api_get("/meta/fields/" + urllib.parse.quote(m), token)
            attrs = (f.get("data") or {}).get("attributes") if isinstance(f, dict) else None
            if isinstance(attrs, dict):
                flds = sorted("%s:%s" % (fn, (fd.get("type") if isinstance(fd, dict) else "?"))
                              for fn, fd in attrs.items())
                print("\n  ## %s  (%d fields, %d records)" % (m, len(flds), r["count"]))
                print("     " + ", ".join(flds))
            else:
                print("\n  ## %s  fields shape: %s"
                      % (m, list(f.keys()) if isinstance(f, dict) else type(f)))
        except Exception as e:
            print("\n  ## %s  fields error: %s" % (m, str(e)[:120]))

    print("\n[5] RAW JSON (module -> count):")
    print(json.dumps({"base": BASE, "modules_total": len(names), "results": results}, indent=1))
    print("\n=== END ===")
    return 0


if __name__ == "__main__":
    sys.exit(main())
