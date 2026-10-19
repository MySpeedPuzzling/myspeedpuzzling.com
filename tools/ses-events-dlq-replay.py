#!/usr/bin/env python3
"""Replays SES events parked in the SQS dead-letter queue `ses-events-dlq`.

SNS parks an event there when an HTTPS subscription (the app's /-/ses-events, Listmonk's SES webhook) kept failing
for about an hour. A parked message is the original signed SNS envelope, and neither receiver rejects old messages,
so replaying = POSTing the envelope to the endpoints of its topic again. A message is deleted from the queue only
when every endpoint of its topic answered 2xx; anything else stays for the next run.

Usage (on a machine with the AWS CLI signed in to the MySpeedPuzzling project):
    python3 -I tools/ses-events-dlq-replay.py            # dry run: lists what is parked
    python3 -I tools/ses-events-dlq-replay.py --replay   # posts and deletes what was accepted

docs/features/mass-mailing/README.md, "SES events dead-letter queue".
"""
import json
import subprocess
import sys
import urllib.error
import urllib.request

REGION = 'eu-north-1'
QUEUE = 'ses-events-dlq'
ENDPOINTS = {
    'ses-events-app': ['https://myspeedpuzzling.com/-/ses-events'],
    'ses-events-newsletter': ['https://myspeedpuzzling.com/-/ses-events',
                              'https://listmonk.myspeedpuzzling.com/webhooks/service/ses'],
}


def aws(*args):
    out = subprocess.run(['aws', '--region', REGION, '--output', 'json', *args],
                         capture_output=True, text=True, check=True).stdout
    return json.loads(out) if out.strip() else {}


def post(url, body):
    request = urllib.request.Request(url, data=body.encode(), method='POST',
                                     headers={'Content-Type': 'text/plain; charset=UTF-8'})
    try:
        with urllib.request.urlopen(request, timeout=15) as response:
            return response.status
    except urllib.error.HTTPError as e:
        return e.code
    except OSError:
        return None


def main():
    replay = '--replay' in sys.argv
    queue_url = aws('sqs', 'get-queue-url', '--queue-name', QUEUE)['QueueUrl']
    seen, replayed, kept = set(), 0, 0
    while True:
        # visibility timeout: a message we keep stays hidden for the rest of this run, so the loop ends
        batch = aws('sqs', 'receive-message', '--queue-url', queue_url, '--max-number-of-messages', '10',
                    '--visibility-timeout', '600' if replay else '30', '--wait-time-seconds', '1').get('Messages', [])
        batch = [m for m in batch if m['MessageId'] not in seen]
        if not batch:
            break
        for message in batch:
            seen.add(message['MessageId'])
            envelope = json.loads(message['Body'])
            topic = envelope.get('TopicArn', '').rsplit(':', 1)[-1]
            event = json.loads(envelope.get('Message', '{}'))
            kind = event.get('eventType') or event.get('notificationType')
            print(f"{envelope.get('Timestamp')} {topic} {kind} {event.get('mail', {}).get('messageId')}")
            if not replay:
                continue
            statuses = [post(url, message['Body']) for url in ENDPOINTS.get(topic, [])]
            if statuses and all(s is not None and 200 <= s < 300 for s in statuses):
                aws('sqs', 'delete-message', '--queue-url', queue_url, '--receipt-handle', message['ReceiptHandle'])
                replayed += 1
            else:
                print(f'  kept, answers {statuses}')
                kept += 1
    print(f'{len(seen)} parked' + (f', {replayed} replayed, {kept} kept' if replay else ' (dry run, --replay to post)'))


if __name__ == '__main__':
    main()
