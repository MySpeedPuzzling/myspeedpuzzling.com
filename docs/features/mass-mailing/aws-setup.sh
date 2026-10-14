#!/usr/bin/env bash
# Amazon SES setup for MySpeedPuzzling (README.md "Rollout", steps 1-2). Run once per phase, from a machine with the
# AWS CLI v2 signed in with `aws login` (short-term credentials, no access keys). The account is a "projects"
# account: besides us-east-1/us-west-2 only its selected region (eu-north-1, Stockholm) is allowed.
#
#   ./aws-setup.sh identities   # domain identities + DKIM + MAIL FROM; prints the DNS records
#   ./aws-setup.sh streams      # configuration sets, SNS topics, event destinations, suppression
#   ./aws-setup.sh subscribe    # SNS → https://myspeedpuzzling.com/-/ses-events (+ Listmonk)
#   ./aws-setup.sh alarms       # bounce/complaint rate alarms → e-mail (the address confirms the subscription)
#   ./aws-setup.sh show         # what exists now
#
# "subscribe" only after a deploy with SES_EVENTS_TOPIC_ARNS set to the two topic ARNs "streams" printed - the
# endpoint answers 404 while that variable is empty, and SNS would give up on the subscription confirmation.
# Nothing here sends e-mail or changes what the app sends: the switch stays in the MAILER_* env values.
set -euo pipefail

REGION="eu-north-1"
DOMAINS=("mail.myspeedpuzzling.com" "notify.myspeedpuzzling.com" "news.myspeedpuzzling.com")
APP_ENDPOINT="https://myspeedpuzzling.com/-/ses-events"
LISTMONK_ENDPOINT="https://listmonk.myspeedpuzzling.com/webhooks/service/ses"
# Configuration sets = MailStream values; the newsletter's opens and clicks are tracked by Listmonk, not SES
APP_STREAMS=("transactional" "notifications")
NEWSLETTER_STREAM="newsletter"
EVENTS_TOPIC="ses-events-app"           # configuration sets transactional + notifications
NEWSLETTER_TOPIC="ses-events-newsletter" # news. identity feedback (Bounce + Complaint) - app and Listmonk
ALARMS_TOPIC="ses-alarms"
ALARMS_EMAIL="j.mikes@me.com"

aws() { command aws --region "$REGION" --no-cli-pager "$@"; }
account_id() { aws sts get-caller-identity --query Account --output text; }
topic_arn() { echo "arn:aws:sns:${REGION}:$(account_id):$1"; }

identities() {
    for domain in "${DOMAINS[@]}"; do
        aws sesv2 create-email-identity --email-identity "$domain" \
            --dkim-signing-attributes NextSigningKeyLength=RSA_2048_BIT >/dev/null
        aws sesv2 put-email-identity-mail-from-attributes --email-identity "$domain" \
            --mail-from-domain "bounce.${domain}" --behavior-on-mx-failure USE_DEFAULT_VALUE
    done

    echo "DNS records for Cloudflare (DNS only, not proxied):"
    for domain in "${DOMAINS[@]}"; do
        for token in $(aws sesv2 get-email-identity --email-identity "$domain" --query 'DkimAttributes.Tokens' --output text); do
            echo "CNAME ${token}._domainkey.${domain} -> ${token}.dkim.amazonses.com"
        done
        echo "MX    bounce.${domain} -> 10 feedback-smtp.${REGION}.amazonses.com"
        echo "TXT   bounce.${domain} -> \"v=spf1 include:amazonses.com ~all\""
    done
}

create_topic() {
    local name="$1" arn
    arn=$(aws sns create-topic --name "$name" --query TopicArn --output text)
    # SES may publish to the topic only for this account (confused-deputy guard)
    aws sns set-topic-attributes --topic-arn "$arn" --attribute-name Policy --attribute-value "$(cat <<JSON
{"Version":"2012-10-17","Statement":[{"Effect":"Allow","Principal":{"Service":"ses.amazonaws.com"},"Action":"SNS:Publish",
"Resource":"${arn}","Condition":{"StringEquals":{"AWS:SourceAccount":"$(account_id)"}}}]}
JSON
)"
    echo "$arn"
}

streams() {
    local events_arn newsletter_arn
    events_arn=$(create_topic "$EVENTS_TOPIC")
    newsletter_arn=$(create_topic "$NEWSLETTER_TOPIC")

    for stream in "${APP_STREAMS[@]}" "$NEWSLETTER_STREAM"; do
        aws sesv2 create-configuration-set --configuration-set-name "$stream" \
            --delivery-options TlsPolicy=REQUIRE --sending-options SendingEnabled=true
    done

    # Opens and clicks are recorded only for notifications (README: transactional has no tracking)
    local transactional_types='["SEND","REJECT","BOUNCE","COMPLAINT","DELIVERY","DELIVERY_DELAY","RENDERING_FAILURE"]'
    local notification_types='["SEND","REJECT","BOUNCE","COMPLAINT","DELIVERY","DELIVERY_DELAY","RENDERING_FAILURE","OPEN","CLICK"]'
    aws sesv2 create-configuration-set-event-destination --configuration-set-name transactional \
        --event-destination-name app --event-destination \
        "{\"Enabled\":true,\"MatchingEventTypes\":${transactional_types},\"SnsDestination\":{\"TopicArn\":\"${events_arn}\"}}"
    aws sesv2 create-configuration-set-event-destination --configuration-set-name notifications \
        --event-destination-name app --event-destination \
        "{\"Enabled\":true,\"MatchingEventTypes\":${notification_types},\"SnsDestination\":{\"TopicArn\":\"${events_arn}\"}}"

    # Listmonk's SES handler reads identity feedback notifications, not configuration set events
    for type in Bounce Complaint; do
        aws ses set-identity-notification-topic --identity news.myspeedpuzzling.com \
            --notification-type "$type" --sns-topic "$newsletter_arn"
        aws ses set-identity-headers-in-notifications-enabled --identity news.myspeedpuzzling.com \
            --notification-type "$type" --enabled
    done

    # BOUNCE only - never COMPLAINT: a complaint about a digest must not block sign-in links (README "Rules")
    aws sesv2 put-account-suppression-attributes --suppressed-reasons BOUNCE

    echo "SES_EVENTS_TOPIC_ARNS=${events_arn},${newsletter_arn}"
}

subscribe() {
    aws sns subscribe --topic-arn "$(topic_arn "$EVENTS_TOPIC")" --protocol https \
        --notification-endpoint "$APP_ENDPOINT"
    aws sns subscribe --topic-arn "$(topic_arn "$NEWSLETTER_TOPIC")" --protocol https \
        --notification-endpoint "$APP_ENDPOINT"
    aws sns subscribe --topic-arn "$(topic_arn "$NEWSLETTER_TOPIC")" --protocol https \
        --notification-endpoint "$LISTMONK_ENDPOINT"
}

# AWS reviews an account at 5 % bounces / 0.1 % complaints and pauses it at 10 % / 0.5 % - warn before the review
alarm() {
    aws cloudwatch put-metric-alarm --alarm-name "$1" --alarm-description "$2" \
        --namespace AWS/SES --metric-name "$3" --statistic Maximum --period 3600 --evaluation-periods 1 \
        --threshold "$4" --comparison-operator GreaterThanOrEqualToThreshold --treat-missing-data notBreaching \
        --alarm-actions "$(topic_arn "$ALARMS_TOPIC")" --ok-actions "$(topic_arn "$ALARMS_TOPIC")"
}

alarms() {
    aws sns create-topic --name "$ALARMS_TOPIC" >/dev/null
    aws sns subscribe --topic-arn "$(topic_arn "$ALARMS_TOPIC")" --protocol email --notification-endpoint "$ALARMS_EMAIL"
    alarm ses-bounce-rate-4pct "SES account bounce rate >= 4 % (AWS reviews at 5 %, pauses at 10 %)" \
        Reputation.BounceRate 0.04
    alarm ses-complaint-rate-0.08pct "SES account complaint rate >= 0.08 % (AWS reviews at 0.1 %, pauses at 0.5 %)" \
        Reputation.ComplaintRate 0.0008
}

show() {
    aws sesv2 get-account --query '{production:ProductionAccessEnabled,quota:SendQuota,suppression:SuppressionAttributes}'
    aws sesv2 list-email-identities --query 'EmailIdentities[].[IdentityName,VerificationStatus]' --output table
    aws sesv2 list-configuration-sets --output table
    for topic in "$EVENTS_TOPIC" "$NEWSLETTER_TOPIC"; do
        aws sns list-subscriptions-by-topic --topic-arn "$(topic_arn "$topic")" \
            --query 'Subscriptions[].[Endpoint,SubscriptionArn]' --output table
    done
    aws cloudwatch describe-alarms --alarm-name-prefix ses- --query 'MetricAlarms[].[AlarmName,StateValue]' --output table
}

case "${1:-}" in
    identities|streams|subscribe|alarms|show) "$1" ;;
    *) sed -n '2,13p' "$0"; exit 1 ;;
esac
