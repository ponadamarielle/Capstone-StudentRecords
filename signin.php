    <?php
    session_start();
    $error = "";

    $DBHost = "localhost";
    $DBUser = "root";
    $DBPass = "";
    $DBName = "db_pup_eRecords";

    $conn = mysqli_connect($DBHost, $DBUser, $DBPass, $DBName);

    if(!$conn) {
        die("Connection failed: " .mysqli_connect_error());
    }

    if($_SERVER['REQUEST_METHOD'] == 'POST'){
        $email = $_POST['email'];
        $pass = $_POST['password'];

        $sql = "SELECT * FROM accounts WHERE email='$email'";
        $result = mysqli_query($conn, $sql);

        if(mysqli_num_rows($result) > 0){
            $row = mysqli_fetch_assoc($result);

            if(password_verify($pass, $row['pass'])){
                if($row['status'] !== 'Active') {
                    $error = "Your account is inactive. Please contact admin.";
                } else {
                    $_SESSION['email'] = $row['email'];
                    $_SESSION['role'] = $row['role'];
                    $_SESSION['name'] = $row['name'];

                    if ($row['role'] == 'Super Admin') {
                        header("Location: superadmin/dashboard.php");
                        exit();
                    } elseif ($row['role'] == 'Registrar') {
                        header("Location: admin/dashboard.php");
                        exit();
                    }
                }
            } else {
                $error = "Invalid Credentials";
            }

        } else {
            $error = "Invalid Credentials";
        }
    }

    ?>
<!DOCTYPE html>
<html>
<head>
    <title> PUP Student Records </title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

<style>
    body, html {
        margin: 0;
        padding: 0;
        height: 100%;
        overflow: hidden;
    }
    .main-container {
        display: flex;
        height: 100vh;
    }
    .front-image {
        flex: 6.5; 
        background: url('pupbinan.jpg') no-repeat center center;
        background-size: cover;
    }
    .signin {
        flex: 3.5;
        background-color: #fafafa;
        display: flex;
        justify-content: center;
        align-items: flex-start;
        padding: 250px 20px 20px 20px;
    }
    .signin-box {
        width: 100%;
        max-width: 400px;
        text-align: center;
    }
    .title {
        font-family: "Cambria";
        font-size: 30px;
        font-weight: bold;
        color: #800503;
    }
    .sub-title {
        font-family: "Verdana";
        font-size: 15px;
        color: black;
    }
    .input-container {
        position: relative;
        width: 100%;
        margin: 60px auto;
    }
    .signin-btn {
        width: 400px;
        padding: 12px;
        border: none;
        background: #800503;
        color: white;
        font-size: 17px;
        cursor: pointer;
        border-radius: 6px;
        margin-top: 10px;
        font-family: "Cambria";
        font-weight: bold;
    }


</style>
</head>

<body>
    <div class = "main-container">
    <div class = "front-image"></div>

    <div class = "signin">
        <div class= "signin-box">
            <h1 class = "title">PUP eRecords</h1>
            <p class = "sub-title">SIGN-IN</p>

            <form name = "signin" method = "POST">
                <div class = "input-container">
                    <div class = "form-floating mb-3">
                        <input type="email" class="form-control" id="email" name="email" placeholder="email" required>
                        <label for="email">Email</label>
                    </div>
                    <div class = "form-floating mb-3">
                        <input type = "password" class="form-control" id="password" name="password" placeholder = "Password" required> 
                        <label for="password">Password</label>
                    </div>

                     <?php if($error != ''): ?>
                        <div class="alert alert-danger" role="alert">
                        <?php echo $error; ?>
                    </div>
                    <?php endif; ?>
                    
                    <button type = "submit" name = "signin" class = "signin-btn">Sign In</button>
                </div>
            </form>
        </div>

    </div>
    </div>

</body>
</html>