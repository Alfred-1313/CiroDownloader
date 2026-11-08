# Link reactions

Newly accepted YouTube, Instagram, Pinterest, TikTok and X links receive a native Telegram 👾 reaction on the user's original message. This defaults on in both private chats and groups, including `/download@BotName` commands. The bot creates the job and its status before requesting the reaction; workers can process the job while that optional request completes.

Administrators can open **🎨 شخصی‌سازی → 👾 تنظیم ری‌اکشن لینک** to select a preset, enter another supported Telegram reaction emoji, or disable reactions. The choice persists across restarts and applies to future requests. Disabling the feature does not remove reactions from older messages. The blue section heading is display-only, and the controls use the neutral default style.

Only one standard reaction emoji can be selected. Arbitrary emoji, multiple emoji, text and Premium custom emoji are rejected while preserving the current setting. In groups, Telegram can restrict which reactions are available; choose an emoji that the group allows. The default 👾 does not override those restrictions.

Denied, full, busy, cooldown and duplicate requests do not receive a reaction. A rejected or failed reaction never changes the job's outcome or replaces media diagnostics. Reaction calls have a two-second connection timeout and a three-second total timeout, without retries. Logs record only the reaction error code.

The HTTP regression in `tests/reactions.php` checks the actual API payload and timeout behavior against a local endpoint. Availability of a particular reaction in a deployed chat still depends on Telegram and that chat's settings.

## Music links

Telegram has no music emoji among the reactions a bot may use, and custom-emoji reactions work only where chat administrators allowed them. Spotify and SoundCloud links therefore use their own choice, **🔥 by default**, shown in the same screen (**⚙️ قابلیت‌ها ← ری‌اکشن لینک**) under "ری‌اکشن روی لینک موسیقی": one of the preset emoji, or "مثل بقیهٔ لینک‌ها". When reactions are turned off, music links get none either. The choice is stored as `link_reaction.music`.
