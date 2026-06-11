#!/usr/bin/env bash
# Check for improvement requests (approved/pending) and output notifications.
# Designed for cronjob with no_agent=True — stdout is delivered verbatim.
# Silent when nothing new to report (watchdog pattern).
# Uses API on localhost:8080 (PHP unified server).

API="http://127.0.0.1:8080"
ADMIN_EMAIL="0000@mailo.com"
STATE_FILE="/home/ubuntu/causeries-quart-heure/.last_improvement_check"

# Load old notified IDs
OLD_IDS="[]"
if [ -f "$STATE_FILE" ]; then
    OLD_IDS=$(cat "$STATE_FILE")
fi

HAS_NEW=0
OUTPUT=""

# Helper: fetch improvements by status
fetch() {
    local status="$1"
    curl -sf "$API/api/improvements?email=$ADMIN_EMAIL&status=$status" 2>/dev/null
}

# Check approved improvements (new features to implement)
DATA=$(fetch "approved")
if [ -n "$DATA" ]; then
    echo "$DATA" | python3 -c "
import sys, json
data = json.load(sys.stdin)
if data.get('ok') and data.get('improvements'):
    old_ids = set(json.loads('$OLD_IDS'))
    for imp in data['improvements']:
        if imp['id'] not in old_ids:
            print('APPROVED:' + json.dumps(imp))
" 2>/dev/null | while read -r line; do
    if [[ "$line" == APPROVED:* ]]; then
        imp=$(echo "$line" | cut -d: -f2-)
        title=$(echo "$imp" | python3 -c "import sys,json; print(json.load(sys.stdin)['title'])")
        text=$(echo "$imp" | python3 -c "import sys,json; print(json.load(sys.stdin)['text'])")
        email=$(echo "$imp" | python3 -c "import sys,json; print(json.load(sys.stdin)['email'].split('@')[0])")
        comment=$(echo "$imp" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('review_comment',''))")
        
        echo ""
        echo "✅ **NOUVELLE DEMANDE D'AMÉLIORATION VALIDÉE** ✨"
        echo ""
        echo "**$title**"
        echo "$text"
        echo ""
        [ -n "$comment" ] && echo "💬 Commentaire : $comment"
        echo "Par : $email"
        echo "—"
        HAS_NEW=1
    fi
done
fi

# Check pending improvements (new requests to review)
DATA=$(fetch "pending")
if [ -n "$DATA" ]; then
    echo "$DATA" | python3 -c "
import sys, json
data = json.load(sys.stdin)
if data.get('ok') and data.get('improvements'):
    old_ids = set(json.loads('$OLD_IDS'))
    for imp in data['improvements']:
        if imp['id'] not in old_ids:
            print('PENDING:' + json.dumps(imp))
" 2>/dev/null | while read -r line; do
    if [[ "$line" == PENDING:* ]]; then
        imp=$(echo "$line" | cut -d: -f2-)
        title=$(echo "$imp" | python3 -c "import sys,json; print(json.load(sys.stdin)['title'])")
        text=$(echo "$imp" | python3 -c "import sys,json; print(json.load(sys.stdin)['text'])")
        email=$(echo "$imp" | python3 -c "import sys,json; print(json.load(sys.stdin)['email'].split('@')[0])")
        
        echo ""
        echo "🆕 **NOUVELLE DEMANDE D'AMÉLIORATION**"
        echo ""
        echo "**$title**"
        echo "$text"
        echo "Par : $email"
        echo "👉 http://164.132.47.10:8080/prevention pour valider"
        echo ""
        HAS_NEW=1
    fi
done
fi

# Save notified IDs (all current improvements)
ALL_IDS=$(curl -sf "$API/api/improvements?email=$ADMIN_EMAIL" 2>/dev/null | python3 -c "
import sys, json
data = json.load(sys.stdin)
if data.get('ok') and data.get('improvements'):
    print(json.dumps([i['id'] for i in data['improvements']]))
else:
    print('$OLD_IDS')
" 2>/dev/null)
if [ -n "$ALL_IDS" ]; then
    echo "$ALL_IDS" > "$STATE_FILE"
fi

exit 0
