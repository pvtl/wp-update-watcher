<?php
/**
 * @var $name
 * @var $message
 * @var $date
 */
?>

<!doctype html>
<html>
<head>
    <style>
        body { font-family: sans-serif; font-size: 14px; color: #2f2f2f; }
        p { margin: 0 0 1em; line-height: 1.5em; }
    </style>
</head>
<body>

<p><img src="https://www.pivotalagency.com.au/assets/images/pivotal.png" style="width: 130px; height: auto;" /></p>
<p><?= $name ?></p>
<p><strong>Report Date: </strong><?php echo $date; ?></p>

<hr style="margin: 20px 0; border: 0; border-bottom: 1px dotted #eee;" />

<p><?php echo nl2br($message); ?></p>

<hr style="margin: 20px 0; border: 0; border-bottom: 1px dotted #eee;" />

<p>It's important to keep your website up to date to ensure known security vulnerabilities are patched and secured.</p>

</body>
</html>
