<?php
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $to = "mna25867@gmail.com";
    $subject = "Testing";
    $message = "Testing";
    $headers = "From: noreply@gmail.com";

    if (mail($to, $subject, $message, $headers)) {
        $result = "Email sent successfully!";
    } else {
        $result = "Failed to send email.";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Send Email Example</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            text-align: center;
            padding-top: 50px;
            background-color: #f5f5f5;
        }
        button {
            padding: 15px 30px;
            font-size: 18px;
            background-color: #3498db;
            border: none;
            border-radius: 8px;
            color: white;
            cursor: pointer;
        }
        button:hover {
            background-color: #2980b9;
        }
        .message {
            margin-top: 20px;
            font-size: 20px;
            color: green;
        }
    </style>
</head>
<body>

<h2>Send Test Email</h2>

<form method="post">
    <button type="submit">Send Email</button>
</form>

<?php if (!empty($result)) { ?>
    <div class="message"><?php echo $result; ?></div>
<?php } ?>

</body>
</html>
