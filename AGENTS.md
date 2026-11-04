# Project working rules

- Publish authorized changes to GitHub. Do not connect to, deploy to, or change the user's server unless the user explicitly requests deployment again.
- Bot UI is Persian. Prefer clear bold headings and blockquotes in default messages. Installer output stays English.
- Register UI messages, captions, buttons, and update notifications in `resources/ui.json` with an explicit `audience` of `user` or `admin`. Use `Ui` in PHP and the shared catalog in the host updater. Do not introduce hardcoded product strings in handlers.
- Personalization exposes only active UI fields ordinary users see. Every new user-facing feature must add its messages, captions and buttons to Personalization with `audience: user`. Administrator-only panels, status, cookies, updater and editor controls use `audience: admin` and must not be editable through lists, callbacks, legacy routes or saved edit sessions. Keep user-facing access notices editable even though administrators configure them.
- User buttons support title, semantic color and `icon_custom_emoji_id`; user texts and captions preserve native Telegram entities and supported HTML. Keep deprecated UI fields hidden from the editor while retaining compatibility where needed.
- Premium custom emoji depend on Telegram account eligibility. Preview edits before committing them, and retain working settings when Telegram rejects a preview.
- Preserve one status message across inspection, quality selection, download, upload, and errors. Only download after an authenticated owner selects an offered quality.
- Enforce admin-only access in message handlers, callbacks, and the worker before delivering files. Keep the restriction notice editable.
- The main administration panel replaces the home reply keyboard with its own reply controls and a return to the main menu. Keep administration tools inside that panel and exclude their labels from Personalization.
- Keep README minimal. Put detailed operational explanations in focused documents.
