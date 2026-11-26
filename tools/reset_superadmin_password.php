<?php
// Emergency Super Admin password reset.
// Run it from the server's command line only, for example:
//   C:\xampp\php\php.exe C:\xampp\htdocs\CAPSTONE\tools\reset_superadmin_password.php
//   C:\xampp\php\php.exe C:\xampp\htdocs\CAPSTONE\tools\reset_superadmin_password.php name@example.com

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$DBHost = 'localhost';
$DBUser = 'root';
$DBPass = '';
$DBName = 'db_pup_eRecords';

mysqli_report(MYSQLI_REPORT_OFF);
$conn = @mysqli_connect($DBHost, $DBUser, $DBPass, $DBName);
if (!$conn) {
    fwrite(STDERR, 'Could not connect to the database: ' . mysqli_connect_error() . PHP_EOL);
    exit(1);
}
mysqli_set_charset($conn, 'utf8mb4');

function ask($prompt)
{
    echo $prompt;
    $line = fgets(STDIN);
    return $line === false ? '' : trim($line);
}

function makeTempPassword()
{
    $sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnopqrstuvwxyz', '23456789', '!@#$%&*?'];
    $chars = [];
    foreach ([0, 0, 1, 1, 1, 1, 2, 2, 3, 3] as $setIndex) {
        $set = $sets[$setIndex];
        $chars[] = $set[random_int(0, strlen($set) - 1)];
    }
    for ($i = count($chars) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        $tmp = $chars[$i];
        $chars[$i] = $chars[$j];
        $chars[$j] = $tmp;
    }
    return implode('', $chars);
}

$admins = [];
$result = mysqli_query($conn, "SELECT id, name, email, status FROM accounts WHERE role = 'Super Admin' ORDER BY id");
while ($result && ($row = mysqli_fetch_assoc($result))) {
    $admins[strtolower($row['email'])] = $row;
}
if (!$admins) {
    fwrite(STDERR, 'No Super Admin account was found.' . PHP_EOL);
    exit(1);
}

$email = strtolower(trim($argv[1] ?? ''));
if ($email === '' && count($admins) === 1) {
    $email = array_key_first($admins);
}
if ($email === '') {
    echo 'Super Admin accounts:' . PHP_EOL;
    foreach ($admins as $admin) {
        echo '  - ' . $admin['email'] . ' (' . $admin['name'] . ', ' . $admin['status'] . ')' . PHP_EOL;
    }
    $email = strtolower(ask('Email to reset: '));
}
if (!isset($admins[$email])) {
    fwrite(STDERR, 'That email is not a Super Admin account.' . PHP_EOL);
    exit(1);
}

$account = $admins[$email];
echo 'Account: ' . $account['name'] . ' <' . $account['email'] . '> (' . $account['status'] . ')' . PHP_EOL;
if ($account['status'] !== 'Active') {
    echo 'Warning: this account is not Active, so it still cannot sign in until it is activated.' . PHP_EOL;
}
if (strtoupper(ask('Type YES to reset the password: ')) !== 'YES') {
    echo 'Cancelled.' . PHP_EOL;
    exit(0);
}

$temp = makeTempPassword();
$hash = password_hash($temp, PASSWORD_DEFAULT);

$hasFirstLogin = false;
$columns = mysqli_query($conn, "SHOW COLUMNS FROM accounts LIKE 'is_first_login'");
if ($columns && mysqli_num_rows($columns) > 0) {
    $hasFirstLogin = true;
}

$sql = $hasFirstLogin
    ? "UPDATE accounts SET pass = ?, is_first_login = 1 WHERE id = ? AND role = 'Super Admin'"
    : "UPDATE accounts SET pass = ? WHERE id = ? AND role = 'Super Admin'";
$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) {
    fwrite(STDERR, 'Could not prepare the update: ' . mysqli_error($conn) . PHP_EOL);
    exit(1);
}
$id = (int) $account['id'];
mysqli_stmt_bind_param($stmt, 'si', $hash, $id);
if (!mysqli_stmt_execute($stmt)) {
    fwrite(STDERR, 'The password could not be updated: ' . mysqli_stmt_error($stmt) . PHP_EOL);
    exit(1);
}
mysqli_stmt_close($stmt);

$lock = @mysqli_prepare($conn, 'DELETE FROM login_attempts WHERE email = ?');
if ($lock) {
    mysqli_stmt_bind_param($lock, 's', $email);
    mysqli_stmt_execute($lock);
    mysqli_stmt_close($lock);
}

echo PHP_EOL . 'Password reset for ' . $account['email'] . '.' . PHP_EOL;
echo 'Temporary password: ' . $temp . PHP_EOL;
echo 'Sign in with it, then change the password right away. It is shown only once.' . PHP_EOL;
