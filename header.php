<?php
// Session start kora (jodi aage na kora thake)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SCL Dormitory System</title>
    
    <!-- âœ… Favicon (Ekbar add korlei sob page-e pabe) -->
    <link rel="icon" type="image/png" href="assets/images/favicon.png?v=2">
    <link rel="shortcut icon" type="image/png" href="assets/images/favicon.png?v=2">

    <!-- Bootstrap & FontAwesome (Sob page-e lagbe tai ekhane rakhlam) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Custom CSS (Jodi thake) -->
    
        <!-- Custom CSS -->
        
    <style>
        /* âœ… Universal Layout Fix */
            html, body {
                height: 100%;
                margin: 0;
                padding: 0;
                width: 100%;
                
                /* í ½í´¥ Key Fix: Column Direction */
                display: flex;
                flex-direction: column; 
                /* justify-content: center; <-- Eita login page e thakle footer majhe chole ashe, tai sabdhan! */
            }
            
            /* âœ… Content Wrapper: Eta content ke majhe rakhbe, footer ke niche thelbe */
            .main-wrapper {
                flex: 1; /* Pura faka jayga nibe */
                display: flex;
                justify-content: center; /* Horizontally Center */
                align-items: center;     /* Vertically Center */
                width: 100%;
            }
            
            /* âœ… Footer Style (Same as Register) */
            .scl-footer {
                background: transparent !important;
                color: yellow !important;
                text-align: center;
                padding: 15px 0;
                font-weight: 600;
                text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.9);
                width: 100%;
                flex-shrink: 0; /* Footer jeno chapa na khay */
            }

          
          /* Mobile e jeno arektu clear thake */
          @media (max-width: 768px) {
              .scl-footer {
                  font-size: 12px;
                  background: rgba(0,0,0,0.3) !important; /* Mobile e halka kalo background */
                  padding: 10px 0;
              }
          }


    </style>

</head>
<body>
