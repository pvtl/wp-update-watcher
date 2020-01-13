<!doctype html>
<html>
<head>
<style>
    body { font-family: sans-serif; font-size: 14px; color: #2f2f2f; }
    p { margin: 0 0 1em; line-height: 1.5em; }
</style>
</head>
<body>

<p>Hi <?= $name ?>,<br />
As part of our commitment at Pivotal Agency to keep your website secure we have completed a routine review of your website and determined that the following items are out of date and should be updated:</p>

<hr style="margin: 20px 0; border: 0; border-bottom: 1px dotted #eee;" />

<p><?php echo nl2br($message); ?></p>

<hr style="margin: 20px 0; border: 0; border-bottom: 1px dotted #eee;" />

<p>It's important to keep your website up to date to ensure known security vulnerabilities are patched and secured.</p>
<p>If you would like us to update and patch your website to keep it secure, please contact your Pivotal Agency account manager (or simply reply to this email) and we'll get it organised for you.</p>

<p>Kind regards,<br />
<a href="https://pivotalagency.com.au" style="color: #F9C503;">Pivotal Agency</a></p>

<p><img src="https://www.pivotalagency.com.au/email-signature/pivotal-agency.gif" style="width: 130px; height: auto;" /></p>
</body>
</html>
