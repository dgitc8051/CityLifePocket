#!/bin/bash
# ShiftPal E2E smoke test
# 透過 Cloudflare 走完整 HTTPS 流程：登入 → 各 API → 登出
# Usage: bash tests/smoke.sh

set -e
BASE="https://shiftpal.tsaimushi.com"
COOKIES="/tmp/shiftpal-smoke-cookies.txt"
rm -f "$COOKIES"

PASS=0
FAIL=0
FAILS=()

check() {
    local name="$1"
    local code="$2"
    local expected="$3"
    if [ "$code" = "$expected" ]; then
        echo "  ✓ $name → $code"
        PASS=$((PASS + 1))
    else
        echo "  ✗ $name → got $code, expected $expected"
        FAIL=$((FAIL + 1))
        FAILS+=("$name (got $code, expected $expected)")
    fi
}

curl_json() {
    local method="$1"
    local path="$2"
    local data="$3"
    local xsrf=$(grep XSRF "$COOKIES" 2>/dev/null | tail -1 | awk '{print $NF}' | python3 -c "import sys, urllib.parse; print(urllib.parse.unquote(sys.stdin.read().strip()))" 2>/dev/null)
    local args=(-s -b "$COOKIES" -c "$COOKIES" --max-time 10
        -H "Accept: application/json"
        -H "Content-Type: application/json"
        -H "X-Requested-With: XMLHttpRequest"
        -H "X-XSRF-TOKEN: $xsrf"
        -H "Origin: $BASE"
        -H "Referer: $BASE/"
        -X "$method"
        -w "%{http_code}"
        -o /tmp/smoke-resp.txt)
    if [ -n "$data" ]; then
        args+=(-d "$data")
    fi
    curl "${args[@]}" "$BASE$path"
}

echo "========================================="
echo "  ShiftPal E2E Smoke Test"
echo "========================================="
echo ""

# --- 1. Public ---
echo "[1] Public endpoints"
code=$(curl -s -o /dev/null -w "%{http_code}" "$BASE/")
check "GET /" "$code" "200"

code=$(curl -s -o /dev/null -w "%{http_code}" "$BASE/api/auth/me" -H "Accept: application/json" -H "Origin: $BASE")
check "GET /api/auth/me (no auth)" "$code" "401"

# --- 2. CSRF + Login ---
echo ""
echo "[2] Auth flow"
code=$(curl -s -c "$COOKIES" --max-time 10 \
    -H "Origin: $BASE" -H "Referer: $BASE/login" \
    -o /dev/null -w "%{http_code}" \
    "$BASE/sanctum/csrf-cookie")
check "GET /sanctum/csrf-cookie" "$code" "204"

code=$(curl_json POST /api/auth/login '{"email":"demo@shiftpal.local","password":"demo1234"}')
check "POST /api/auth/login (correct)" "$code" "200"

# 用 curl_json 才會帶 cookies + XSRF token；先登出避免 already-logged-in
curl_json POST /api/auth/logout '' > /dev/null
curl -s -c "$COOKIES" --max-time 5 -H "Origin: $BASE" -H "Referer: $BASE/login" -o /dev/null "$BASE/sanctum/csrf-cookie"
code=$(curl_json POST /api/auth/login '{"email":"demo@shiftpal.local","password":"WRONG"}')
check "POST /api/auth/login (wrong pw)" "$code" "422"

# 重新登入給後續測試用
curl_json POST /api/auth/login '{"email":"demo@shiftpal.local","password":"demo1234"}' > /dev/null

code=$(curl_json GET /api/auth/me)
check "GET /api/auth/me (logged in)" "$code" "200"

# --- 3. Read endpoints ---
echo ""
echo "[3] Read endpoints"
for ep in dashboard employees shop business-hours holidays shift-templates schedule leaves availability/matrix reports/weekly-hours reports/shift-coverage shift-swap-requests stations attendance/today; do
    code=$(curl_json GET "/api/$ep")
    check "GET /api/$ep" "$code" "200"
done

# --- 4. Employee CRUD ---
echo ""
echo "[4] Employee CRUD"
code=$(curl_json POST /api/employees '{"name":"測試員工","skill_score":5,"level":"junior","employment_type":"part"}')
check "POST /api/employees (valid)" "$code" "201"
NEW_EMP_ID=$(python3 -c "import json; print(json.load(open('/tmp/smoke-resp.txt'))['data']['id'])" 2>/dev/null)

code=$(curl_json POST /api/employees '{"name":"","skill_score":99}')
check "POST /api/employees (invalid)" "$code" "422"

if [ -n "$NEW_EMP_ID" ]; then
    code=$(curl_json PUT "/api/employees/$NEW_EMP_ID" '{"name":"測試員工B","skill_score":6}')
    check "PUT /api/employees/$NEW_EMP_ID" "$code" "200"

    code=$(curl_json DELETE "/api/employees/$NEW_EMP_ID" '')
    check "DELETE /api/employees/$NEW_EMP_ID" "$code" "200"
fi

# --- 5. Shift template CRUD ---
echo ""
echo "[5] Shift template CRUD"
code=$(curl_json POST /api/shift-templates '{"name":"測試班","start_time":"08:00","end_time":"12:00","days_of_week_bitmask":127,"required_score":5,"min_senior_count":0,"min_headcount":1}')
check "POST /api/shift-templates" "$code" "201"
NEW_TPL_ID=$(python3 -c "import json; print(json.load(open('/tmp/smoke-resp.txt'))['data']['id'])" 2>/dev/null)

if [ -n "$NEW_TPL_ID" ]; then
    code=$(curl_json DELETE "/api/shift-templates/$NEW_TPL_ID" '')
    check "DELETE /api/shift-templates/$NEW_TPL_ID" "$code" "200"
fi

# --- 6. Schedule entry ---
echo ""
echo "[6] Schedule entry"
# 用第一個 active 員工（不能用測試剛刪除的）+ 第一個 active template + 30 天後
EMP_ID=$(curl_json GET '/api/employees?status=active' > /dev/null && python3 -c "import json; d=json.load(open('/tmp/smoke-resp.txt')); print(d['data'][0]['id'])" 2>/dev/null)
TPL_ID=$(curl_json GET /api/shift-templates > /dev/null && python3 -c "import json; d=json.load(open('/tmp/smoke-resp.txt')); print([t for t in d['data'] if t['is_active']][0]['id'])" 2>/dev/null)
FUTURE=$(date -d "+30 days" +%Y-%m-%d 2>/dev/null || date -v+30d +%Y-%m-%d 2>/dev/null)

if [ -n "$EMP_ID" ] && [ -n "$TPL_ID" ] && [ -n "$FUTURE" ]; then
    code=$(curl_json POST /api/schedule-entries "{\"employee_id\":$EMP_ID,\"shift_template_id\":$TPL_ID,\"date\":\"$FUTURE\"}")
    check "POST /api/schedule-entries" "$code" "201"
    ENTRY_ID=$(python3 -c "import json; print(json.load(open('/tmp/smoke-resp.txt'))['data']['id'])" 2>/dev/null)

    # 重複建立應該 422 (新版 validator) 或 409 (舊 controller fallback)
    code=$(curl_json POST /api/schedule-entries "{\"employee_id\":$EMP_ID,\"shift_template_id\":$TPL_ID,\"date\":\"$FUTURE\"}")
    if [ "$code" = "422" ] || [ "$code" = "409" ]; then
        echo "  ✓ POST /api/schedule-entries (duplicate) → $code"
        PASS=$((PASS + 1))
    else
        echo "  ✗ POST /api/schedule-entries (duplicate) → $code"
        FAIL=$((FAIL + 1))
    fi

    if [ -n "$ENTRY_ID" ]; then
        code=$(curl_json DELETE "/api/schedule-entries/$ENTRY_ID" '')
        check "DELETE /api/schedule-entries/$ENTRY_ID" "$code" "200"
    fi
fi

# --- 7. Leave flow ---
echo ""
echo "[7] Leave request flow"
LEAVE_DATE=$(date -d "+5 days" +%Y-%m-%d 2>/dev/null || date -v+5d +%Y-%m-%d 2>/dev/null)
code=$(curl_json POST /api/leaves "{\"employee_id\":$EMP_ID,\"start_datetime\":\"$LEAVE_DATE 00:00:00\",\"end_datetime\":\"$LEAVE_DATE 23:59:00\",\"type\":\"personal\",\"reason\":\"test\"}")
check "POST /api/leaves" "$code" "201"
LEAVE_ID=$(python3 -c "import json; print(json.load(open('/tmp/smoke-resp.txt'))['data']['id'])" 2>/dev/null)

if [ -n "$LEAVE_ID" ]; then
    code=$(curl_json POST "/api/leaves/$LEAVE_ID/approve" '{}')
    check "POST /api/leaves/$LEAVE_ID/approve" "$code" "200"

    # Re-approve should 409
    code=$(curl_json POST "/api/leaves/$LEAVE_ID/approve" '{}')
    check "POST /api/leaves/$LEAVE_ID/approve (re)" "$code" "409"
fi

# --- 8. Schedule copy ---
echo ""
echo "[8] Schedule copy"
LAST_WEEK=$(date -d "-7 days" +%Y-%m-%d 2>/dev/null || date -v-7d +%Y-%m-%d 2>/dev/null)
THIS_WEEK=$(date +%Y-%m-%d)
code=$(curl_json POST /api/schedule/copy "{\"source_week\":\"$LAST_WEEK\",\"target_week\":\"$THIS_WEEK\",\"replace_existing\":false}")
# 200 if source has entries; 404 if not
if [ "$code" = "200" ] || [ "$code" = "404" ]; then
    echo "  ✓ POST /api/schedule/copy → $code (200 or 404 expected)"
    PASS=$((PASS + 1))
else
    echo "  ✗ POST /api/schedule/copy → $code"
    FAIL=$((FAIL + 1))
fi

# AutoScheduler preview (不寫 DB)
NEXT_MONDAY=$(date -d "next Monday" +%Y-%m-%d 2>/dev/null || date -v+Mon +%Y-%m-%d 2>/dev/null)
code=$(curl_json POST /api/schedule/auto-generate/preview "{\"week\":\"$NEXT_MONDAY\",\"strategy\":\"balanced\",\"replace_existing\":true}")
check "POST /api/schedule/auto-generate/preview" "$code" "200"

# --- 9. Reports ---
echo ""
echo "[9] Reports"
code=$(curl -s -b "$COOKIES" -o /tmp/smoke-csv.txt -w "%{http_code}" --max-time 10 \
    -H "Origin: $BASE" "$BASE/api/reports/schedule.csv")
check "GET /api/reports/schedule.csv" "$code" "200"
if [ -s /tmp/smoke-csv.txt ]; then
    if head -c 3 /tmp/smoke-csv.txt | xxd | grep -q "ef bb bf"; then
        echo "  ✓ CSV has UTF-8 BOM"
        PASS=$((PASS + 1))
    fi
fi

# --- 10. Logout ---
echo ""
echo "[10] Logout"
code=$(curl_json POST /api/auth/logout '')
check "POST /api/auth/logout" "$code" "200"

code=$(curl_json GET /api/dashboard)
check "GET /api/dashboard (after logout)" "$code" "401"

# --- Summary ---
echo ""
echo "========================================="
echo "  Result: $PASS passed, $FAIL failed"
echo "========================================="
if [ $FAIL -gt 0 ]; then
    echo ""
    echo "Failed tests:"
    printf '  - %s\n' "${FAILS[@]}"
    exit 1
fi
