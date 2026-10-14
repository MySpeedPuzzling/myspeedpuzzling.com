# Streaming the studio to YouTube with OBS

How an organiser streams a round's studio - the host and the finishers on air - to YouTube today, with free tools only: **OBS Studio** (open source, GPL) captures the round's watch page and sends it to **YouTube Live** (free). Nothing is paid, no account anywhere but YouTube's.

Why: until Phase 4 brings HLS, the watch page on MySpeedPuzzling serves a limited number of spectators over WebRTC (`ONLINE_WATCH_MAX_SPECTATORS`, 200 by default - [README.md](README.md) §5.12). YouTube carries any audience, and the watch page offers the YouTube stream to whoever finds the studio full.

```
host + guests ──► studio (LiveKit) ──► watch page ?obs=1 in OBS ──► YouTube Live ──► any number of viewers
                                   └─► watch page on MySpeedPuzzling (up to the limit, under a second behind)
```

## What you need

- A computer that stays on for the whole round (Windows, macOS or Linux) with a stable upload of about **6 Mbit/s** for 1080p (3-4 Mbit/s for 720p). A cable beats Wi-Fi.
- **OBS Studio 30 or newer** - [obsproject.com](https://obsproject.com).
- A **YouTube channel with live streaming enabled**. Turn it on days ahead: YouTube Studio → *Create* → *Go live*. The first activation needs a verified channel and can take **up to 24 hours**.
- The round with **online timing and live video on** (the round's online timing page), and the event **published**: OBS is not signed in, so a draft's watch page answers it "not found".

## 1. YouTube: the stream and its key

1. YouTube Studio → *Create* → *Go live* → **Stream** (not *Webcam*). Or *Manage* → *Schedule stream* a few days ahead - a scheduled stream has a link you can share before the day.
2. Title, description, visibility (*Public* or *Unlisted*), audience (*No, it's not made for kids*).
3. **Stream latency: *Ultra low-latency*** - the stream is then only a few seconds behind the studio, close enough for the chat to follow the interviews. It does not support resolutions above 1080p or closed captions; use *Low latency* if viewers report stalls.
4. Copy the **stream key**. It is a password: whoever has it can stream to your channel. Never paste it into a chat or a document.

## 2. OBS: where to send it

*Settings* → **Stream**: Service *YouTube - RTMPS*, *Use Stream Key*, paste the key (or *Connect Account*).

*Settings* → **Output** (Output mode *Simple* is enough):
- Video bitrate **6000 Kbps** for 1080p at 30 fps, **3500 Kbps** for 720p at 30 fps - YouTube's help page "Choose live encoder settings, bitrates and resolutions" has the current table;
- Encoder: a hardware one when offered (NVIDIA NVENC, Intel QuickSync, AMD, Apple VideoToolbox), else *x264*;
- Audio bitrate 160.

*Settings* → *Output* → *Advanced* mode, if you switch to it: rate control *CBR*, **keyframe interval 2 s** (YouTube wants 2 s, never more than 4).

*Settings* → **Video**: base and output resolution **1920×1080**, **30** fps.

*Settings* → **Audio**: switch *Desktop Audio* and *Mic/Aux* off unless you want them in the stream - the studio's sound comes from the browser source below.

## 3. OBS: the studio as a browser source

In your scene: *Sources* → **+** → **Browser**:

| Setting | Value |
|---|---|
| URL | the round's watch page with `?obs=1` - `https://myspeedpuzzling.com/en/online-rounds/<round id>/watch?obs=1` (the event page's "Watch live" link, plus `?obs=1`) |
| Width × Height | **1920 × 1080** (the size of your canvas) |
| Use custom frame rate | on, **30** |
| **Control audio via OBS** | **on** - the studio's voices go into OBS's mixer and into the stream; off, they play on your speakers and the stream is silent |
| Custom CSS | leave OBS's default - the page is transparent around the video by itself |
| Shutdown source when not visible | **off** - switching scenes must not drop the studio |
| Refresh browser when scene becomes active | off |

What `?obs=1` shows: **the video alone** - the host and the guests on air, as large as the canvas allows, side by side or stacked, nothing else: no names, no clock, no buttons, no site header. Around the video, and whenever nobody is on air, the page is **transparent**: put your own layers *below* the browser source - a "Starting soon" picture, the event's logo, a background - and they show through. Its sound plays without a click (OBS allows it).

Optional layers *above* it: the event's name as a text source, or the round's official clock - the round's projector page (`/en/round-stopwatch/<round id>`) as a second browser source, cropped to the clock.

In the *Audio Mixer* the browser source has its own meter: talk in the studio and set it to peak around -10 dB. *Edit* → *Advanced Audio Properties* → *Audio Monitoring* → *Monitor and Output* lets you hear it in your headphones (never on speakers next to a microphone).

## 4. Before the round

- **Open OBS before you share the watch page's link.** OBS is a spectator like any other and takes one of the studio's places; opened first, it is in before the crowd. If the studio is full when OBS connects, the view stays transparent and tries again by itself every minute.
- **Rehearse** with an *Unlisted* stream: the host opens the studio, you check picture, sound and the delay on the YouTube page.
- If you added the browser source **before** the round's live video was switched on, it shows an empty page: right-click the source → *Properties* → **Refresh cache of current page** (or *Refresh*).
- To have the watch page offer your YouTube stream when the studio is full, send the stream's link to the MySpeedPuzzling team - the round keeps it (`online_round.youtube_url`; the online timing page has no field for it yet). Only an `https://` YouTube address is ever linked.

## 5. Going live

1. *Start Streaming* in OBS.
2. YouTube Studio shows the incoming picture after a few seconds; press *Go live* there (or let a scheduled stream start by itself).
3. Keep YouTube Studio open: it warns about a dropping connection or a low bitrate.
4. After the round: *Stop Streaming* in OBS, then *End stream* in YouTube Studio. YouTube keeps the recording on the channel.

## How far behind the studio it is

| Where | Behind the studio |
|---|---|
| The watch page on MySpeedPuzzling (WebRTC) | under a second |
| OBS itself | a fraction of a second more |
| YouTube, *Ultra low-latency* | a few seconds |
| YouTube, *Low latency* / *Normal* | longer - several seconds up to half a minute |

The round's clock on the watch page and the participants' phones is the server's - YouTube viewers see the studio a few seconds late, the official times are not affected.

## When something is wrong

| What you see | Why, and what to do |
|---|---|
| Nothing at all, the source stays transparent | Nobody is on air yet (the host has not opened the studio), or the studio is full (it tries again every minute), or the round's video was off when the source loaded - refresh the source |
| "Not found" text in the source | The event is a draft or waiting for approval - publish it; or the URL lost its round id |
| Picture, but the stream is silent | *Control audio via OBS* is off, or the source is muted in the mixer |
| The picture froze for a moment and came back | A network drop - the page reconnects by itself, never reloads |
| YouTube says "no data" | The stream key is wrong or expired - copy it again from YouTube Studio |

## What it costs

Nothing but the uplink. OBS is free and open source; YouTube Live is free. MySpeedPuzzling's media server sends OBS the studio once (about 1.5-2 Mbit/s, like any spectator); YouTube does the rest of the distribution.
