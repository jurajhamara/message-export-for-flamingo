=== Message Export for Flamingo ===
Contributors: jurajj
Donate link: https://www.paypal.com/donate/?hosted_button_id=3JNS55YNAWDAY
Tags: flamingo, contact form 7, export, excel, xlsx
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Export Contact Form 7 messages saved by Flamingo to an Excel file. Choose the forms and the period, get one readable row for each message.

== Description ==

Flamingo saves the messages sent through your Contact Form 7 forms. This plugin downloads them as an Excel file (.xlsx) that anyone can read: one row for each message, one column for each field, and the columns are named by the labels your visitors saw in the form.

The plugin adds an Export page to the Flamingo menu. Check the forms you want, choose a period and click the download button. The button shows how many messages the file will have before you download it.

= Filters =

* Forms - one, several or all of them in one file. Forms deleted in Contact Form 7 are listed too, as long as Flamingo still has their messages.
* Period - all time, this month, last month, this year, or your own range of days.
* Sender e-mail - the whole address or a part of it, for example @gmail.com.
* Text - only messages that contain all of the given words.
* Spam - messages marked as spam can be included if you need them.

= The Excel file =

* Column names come from the labels in your Contact Form 7 forms, for example "E-mail" instead of your-email. Fields with the same label in different forms share one column.
* Columns follow the order of the fields in the form. A field removed from the form later still has a column if older messages contain it.
* The first column is the date and time of sending, as a real date you can sort and filter by. It is in your site's time zone and in the date and time format from Settings > General.
* Messages from several forms go on one sheet, with a column that names the form.
* Checkboxes and other fields with several values show them separated by commas.
* Every value stays text. A phone number like +421 900 123 456 keeps its plus sign, and a value starting with = never runs as a formula.
* The header row stays in place when you scroll and has filters switched on.
* Nothing technical: no IP addresses or browser details, no tokens of bot checks such as reCAPTCHA, hCaptcha or Turnstile.

The file opens in Microsoft Excel, LibreOffice and Google Sheets.

= Read only =

The plugin only reads messages from Flamingo. It does not change or delete them, it has no settings and it saves nothing in the database. The file is created in the server's temporary folder, sent to your browser and deleted at once.

= Requirements =

* Flamingo, which saves the messages.
* Contact Form 7 is optional. Without it, for example after you switched to another form plugin, the old messages can still be exported. Columns are then named by the field names and follow the order in the messages.
* The zip extension of PHP, which most hosting providers have. Without it, the Export page tells you so.

= Privacy =

Messages from forms usually contain personal data. The plugin does not send them anywhere and does not connect to any external service. The downloaded file contains everything your forms collected, so store and share it with the same care as the messages themselves.

== Installation ==

1. Install and activate Flamingo, if you do not have it yet. Flamingo saves messages from the moment it is active, older messages cannot be exported.
2. Install this plugin from the Plugins screen in your WordPress admin, or upload the `message-export-for-flamingo` folder to `/wp-content/plugins/`.
3. Activate the plugin.
4. Go to Flamingo > Export.

== Frequently Asked Questions ==

= Where do I find the export? =

In the Flamingo menu, below Inbound Messages: Flamingo > Export.

= Who can export messages? =

Everyone who can see the messages under Flamingo > Inbound Messages, which is administrators by default. The plugin checks the same permission as Flamingo, so if you changed who can read the messages, the same applies to the export.

= Does the plugin change my messages? =

No. It only reads them. Nothing in Flamingo is changed or deleted, and the plugin saves no settings or data of its own.

= Why does a column have a name like "Your name" instead of the label from my form? =

The plugin looks for the label of each field in the Contact Form 7 form: the text in a `<label>` around the field, the line of text before it, or its placeholder. If it finds none, or if the form was deleted or Contact Form 7 is not active, the column is named by the field name, so your-name becomes "Your name". Add a label to the field in the form and the next export uses it, for older messages too.

= Why are two similar fields in separate columns? =

Fields share a column only when their labels are the same, regardless of upper and lower case. "Phone" and "Phone (optional)" stay two columns. Two fields of the same form never share a column.

= Why is the consent checkbox missing from the file? =

Contact Form 7 does not accept a form without its required consent, so the column would say "Yes" in every row. Flamingo keeps the consent with each message, together with its text. Optional consent, such as a newsletter checkbox, has its own column. If the form was deleted or Contact Form 7 is not active, the plugin cannot tell required consent from optional, and the column stays.

= Can I export messages marked as spam? =

Yes. Open More options and check "Also include messages marked as spam". The file then has a Spam column. Messages in the trash are never exported.

= Which date format does the file use? =

The date and time format of your site, from Settings > General. Names of months and days are shown by the spreadsheet application in its own language. If no part of the format can be used in a spreadsheet, the file uses the date format of the reader's computer.

= The Export page says the zip extension of PHP is missing. What now? =

An .xlsx file is a zip archive, and the plugin needs the zip extension of PHP to create it. Ask your hosting provider to enable it.

= How many messages can I export at once? =

Messages are processed in batches, so the memory needed does not grow with their number. In tests, 5,000 messages took a few seconds.

= Can I export messages sent before Flamingo was installed? =

No. Flamingo saves messages from the moment it is active, and Contact Form 7 itself does not keep them.

= Does it export messages saved by other plugins, such as CFDB7? =

No, only messages saved by Flamingo.

== Changelog ==

= 0.1.0 =
* First release.
