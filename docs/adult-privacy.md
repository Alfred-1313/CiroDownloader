# Adult-site message deletion

All sites in `adultServices()` share these controls in **🎨 شخصی‌سازی ← 🔞 سایت‌های بزرگسال ← دسترسی و حذف خودکار مشترک**:

- **🗑 حذف پیام لینک کاربر**: on by default. The bot deletes an accepted human link message before starting inspection or download, in private chats and groups. Group use requires Telegram's delete-messages permission.
- **🗑 حذف خودکار در پی‌وی** and **🗑 حذف خودکار در گروه**: both on by default. They delete the delivered video/audio after **10 seconds** by default; the existing 30/60/180-second choices remain available.

The source switch is independent of the two delivered-file filters. Existing explicit choices are preserved. Turning off source deletion retains the normal link reaction. When source deletion is on, the bot does not react to the removed message.

Only accepted requests delete a message: disabled services, unauthorized senders, busy/full queues and duplicate updates do not cause another deletion. The exact source message ID and chat come from the incoming Telegram update. Internal result/menu choices without a human source ID never delete the bot's own message.

The existing `message_id=0` queue barrier keeps new requests out of the worker while deletion is pending. Only after Telegram confirms deletion does the bot create the status message and release processing. If deletion is refused, returns false or has an uncertain transport outcome, the request fails without starting inspection/download. An editable error in **🎨 شخصی‌سازی ← تنظیمات عمومی ← خطاها** explains how to grant group permissions and retry; a sanitized administrator alert uses the existing transient alert mechanism.

Delivered-file deletion uses the existing persisted `TimedDeletes` rows, caption notice and worker sweep. Source deletion does not change the selected quality, destination topic, ownership checks, file timer or captions. Other media services retain their current behavior.

`tests/adult_privacy.php` covers intake ordering and settings without contacting real Telegram or adult sites. The per-service media regressions cover actual local video/audio uploads and timed deletion. A bot must have the required permissions in its real group for source deletion to succeed.
