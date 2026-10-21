# Screenshots in the feedback form

Signed-in players can add up to 3 pictures to "Send feedback" (`FeedbackController`, modal and full page). They reach
our inbox as **attachments of the feedback e-mail** - shown in its body, saveable like any attachment. Nothing is stored
on our side after the e-mail is built.

## Why attachments, not links

- The e-mail is the only record of a feedback (no table), and Simona reads it on her phone - a picture in the mail needs
  no sign-in and no click.
- A link would need a permanent store with access control (a screenshot can show an e-mail address, a private profile,
  a chat - never a public bucket URL), a retention policy with a prune, and a line in `DeletePlayerStoredFiles` for
  GDPR deletions. Worth it only once feedback gets its own admin inbox - then the pictures belong to that record.
- Size is small: every picture is re-encoded to a JPEG of at most 2000 px (`FeedbackScreenshotLimits`), a few hundred kB,
  so a feedback e-mail is at most a few MB - far below SES's message limit. The bytes sit in the queued
  `SendEmailMessage` (`messenger_messages`, base64) until it is sent.

## How a picture travels

1. **Browser** (`feedback_screenshots_controller.js`, `_feedback_screenshots.html.twig`): "Add screenshot", paste
   (Ctrl+V of a copied screenshot - the hint shows only with a mouse) or drop anywhere on the form. Only
   `PhotoUploadLimits::MIME_TYPES` (never SVG or PDF). Shrunk like every photo (`image_compression.js`) and uploaded in
   the background straight to object storage (`photo_upload.js` → `PhotoUploadStartController` → `DirectPhotoUpload`),
   exactly like the add-time form. Its token goes with the form as `photo_stash[screenshots][]`. Send waits for running
   uploads. One whose upload cannot work goes with the form as a file (`feedback_form[screenshots][]`); without
   JavaScript the plain file input is all there is.
2. **Controller**: reads at most 3 tokens (format-checked), keeps files that came with the form in the player's
   `PhotoStash` too, and dispatches `CollectUserFeedback` with tokens only. A refused form (empty message, CSRF, the
   rate limit) shows the pictures again from the stash.
3. **Handler** (`CollectUserFeedbackHandler`): restores each token **from the signed-in player's own stash** (another
   player's token finds nothing), re-encodes it with `ImageOptimizer::toJpeg()` and attaches the bytes as an inline
   `DataPart` named `screenshot-N.jpg` (`cid:` in `emails/feedback.html.twig`), then discards the stash entries.

## Safety

- **Re-encoding is the protection**: decoding is the real type check (extension and declared type do not matter), and
  the new JPEG carries nothing of the original - no EXIF (a phone photo's GPS, serial numbers), only the first frame,
  nothing appended behind the picture. Imagick's own resource policy limits what it decodes - the same as for every
  other upload of the site.
- File names are ours, never the player's. Bytes, never a path (the worker sending the e-mail has no such file).
- Rate limit `feedback`: 10 feedbacks per player and hour (`config/packages/rate_limiter.php`); the background uploads
  share the `photo_upload` ceiling.
- A picture that cannot be read or is no longer in the stash (expired after 24 h) is left out and the e-mail says
  "N screenshot(s) could not be read" - **the message itself always goes.**
- The form says that only our team sees the pictures and that they may show personal details.

Tests: `tests/Controller/FeedbackControllerTest.php`.
