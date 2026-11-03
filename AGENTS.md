# Project working rules

- Publish authorized changes to GitHub. Do not connect to, deploy to, or change the user's server unless the user explicitly requests deployment again.
- Bot UI is Persian. Prefer clear bold headings and blockquotes in default messages. Installer output stays English.
- Register every new user-facing message, caption, button, and update notification in `resources/ui.json`. Use `Ui` in PHP and the shared catalog in the host updater. Do not introduce hardcoded product strings in handlers.
- Keep every new UI field accessible from Personalization. Buttons must use the shared title, semantic color, and `icon_custom_emoji_id` settings; captions and texts must preserve native Telegram entities and supported HTML.
- Premium custom emoji depend on Telegram account eligibility. Preview edits before committing them, and retain working settings when Telegram rejects a preview.
- Preserve one status message across inspection, quality selection, download, upload, and errors. Only download after an authenticated owner selects an offered quality.
- Enforce admin-only access in message handlers, callbacks, and the worker before delivering files. Keep the restriction notice editable.
- Keep README minimal. Put detailed operational explanations in focused documents.
