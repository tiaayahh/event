<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Planora Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', sans-serif;
        }

        body {
            background-color: #F8F9FA;
            color: #333333;
            display: flex;
            min-height: 100vh;
        }

        /* --- SIDEBAR (Desktop Only) --- */
        .sidebar {
            width: 240px;
            background-color: #FFFFFF;
            border-right: 1px solid #E5E7EB;
            display: flex;
            flex-direction: column;
            position: fixed;
            top: 0;
            bottom: 0;
            left: 0;
            z-index: 100;
        }

        .sidebar-brand {
            background-color: #6366F1; /* Your primary purple brand color */
            color: #FFFFFF;
            padding: 20px;
            font-size: 24px;
            font-weight: 700;
            height: 70px;
            display: flex;
            align-items: center;
        }

        .sidebar-menu {
            list-style: none;
            padding: 20px 0;
        }

        .sidebar-menu li a {
            display: flex;
            align-items: center;
            padding: 14px 24px;
            color: #4B5563;
            text-decoration: none;
            font-weight: 500;
            transition: background 0.2s;
        }

        .sidebar-menu li.active a, .sidebar-menu li a:hover {
            background-color: #F3F4F6;
            color: #111827;
            font-weight: 600;
        }

        .sidebar-menu li a i {
            margin-right: 15px;
            width: 20px;
            text-align: center;
            font-size: 18px;
        }

        /* --- MAIN CONTENT AREA --- */
        .main-wrapper {
            flex: 1;
            margin-left: 240px; /* Accounts for sidebar width */
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            padding-bottom: 0;
        }

        /* --- TOP HEADER --- */
        .header {
            background-color: #6366F1;
            height: 70px;
            display: flex;
            justify-content: flex-end;
            align-items: center;
            padding: 0 30px;
            position: sticky;
            top: 0;
            z-index: 90;
        }

        /* Mobile specific header branding */
        .header-brand-mobile {
            display: none;
            color: #FFFFFF;
            font-size: 24px;
            font-weight: 700;
            margin-right: auto;
        }

        .logout-btn {
            background-color: rgba(255, 255, 255, 0.2);
            color: #FFFFFF;
            border: 1px solid rgba(255, 255, 255, 0.4);
            padding: 8px 16px;
            border-radius: 6px;
            font-weight: 500;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            font-size: 14px;
            transition: background 0.2s;
        }

        .logout-btn:hover {
            background-color: rgba(255, 255, 255, 0.3);
        }

        /* --- DASHBOARD BODY CONTENT --- */
        .content {
            padding: 30px;
            max-width: 1200px;
            width: 100%;
            margin: 0 auto;
            display: flex;
            flex-direction: column;
            gap: 24px;
        }

        /* --- ROW 1: BUDGET & QUICK ACTIONS --- */
        .top-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
        }

        .card {
            background-color: #FFFFFF;
            border: 1px solid #E5E7EB;
            border-radius: 8px;
            padding: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }

        .card-title {
            font-size: 18px;
            font-weight: 600;
            color: #111827;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Budget Details Styling */
        .budget-metric {
            font-size: 15px;
            margin-bottom: 10px;
            color: #4B5563;
        }

        .budget-metric strong {
            color: #111827;
        }

        .status-good {
            color: #10B981;
            font-weight: 600;
        }

        /* Custom Progress Bar matching design */
        .progress-bar-container {
            width: 100%;
            background-color: #E5E7EB;
            height: 8px;
            border-radius: 4px;
            margin-top: 15px;
            overflow: hidden;
        }

        .progress-bar-fill {
            background-color: #6366F1;
            height: 100%;
            width: 50%;
            border-radius: 4px;
        }

        /* Quick Actions Buttons */
        .action-buttons {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .btn {
            width: 100%;
            padding: 12px;
            border-radius: 6px;
            font-size: 15px;
            font-weight: 500;
            text-align: center;
            cursor: pointer;
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-decoration: none;
        }

        .btn-primary {
            background-color: #6366F1;
            color: #FFFFFF;
        }

        .btn-primary:hover {
            background-color: #4F46E5;
        }

        .btn-secondary {
            background-color: #C7D2FE;
            color: #4338CA;
        }

        .btn-secondary:hover {
            background-color: #A5B4FC;
        }

        
        .list-container {
            display: flex;
            flex-direction: column;
        }

        .list-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 0;
            border-bottom: 1px solid #F3F4F6;
            color: #4B5563;
            text-decoration: none;
            font-size: 15px;
        }

        .list-item:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }

        .list-item:hover {
            background-color: #FAFAFA;
        }

        .list-item i.fa-chevron-right {
            color: #D1D5DB;
            font-size: 14px;
        }

        /* Badges for booking statuses */
        .status-badge {
            font-weight: 600;
        }
        .status-badge.confirmed {
            color: #10B981;
        }
        .status-badge.pending {
            color: #F59E0B;
        }

        
        .bottom-nav {
            display: none;
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background-color: #6366F1;
            height: 65px;
            z-index: 100;
            justify-content: space-around;
            align-items: center;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }

        .bottom-nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            color: rgba(255, 255, 255, 0.7);
            text-decoration: none;
            font-size: 11px;
            gap: 4px;
        }

        .bottom-nav-item i {
            font-size: 20px;
        }

        .bottom-nav-item.active, .bottom-nav-item:hover {
            color: #FFFFFF;
        }


    
        
        @media (max-width: 768px) {
            /* Hide Desktop Sidebar */
            .sidebar {
                display: none;
            }

            /* Adjust Main Wrapper bounds for Mobile */
            .main-wrapper {
                margin-left: 0;
                padding-bottom: 75px; /* Creates safe space above bottom navigation bar */
            }

            /* Show branding elements inside mobile header view */
            .header {
                justify-content: space-between;
                padding: 0 20px;
            }

            .header-brand-mobile {
                display: block;
            }

            /* Content adjusts to fill standard spacing */
            .content {
                padding: 20px;
                gap: 20px;
            }

            /* Split the top stacked blocks vertically on mobile */
            .top-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }

            /* Show Bottom Navbar */
            .bottom-nav {
                display: flex;
            }
        }
    </style>
</head>
<body>

    <aside class="sidebar">
        <div class="sidebar-brand">Planora</div>
        <ul class="sidebar-menu">
            <li class="active"><a href="#"><i class="fa-solid fa-house"></i> Home</a></li>
            <li><a href="#"><i class="fa-solid fa-calendar-days"></i> Events</a></li>
            <li><a href="#"><i class="fa-solid fa-shop"></i> Vendors</a></li>
            <li><a href="#"><i class="fa-solid fa-book-bookmark"></i> Bookings</a></li>
            <li><a href="#"><i class="fa-solid fa-user"></i> Profile</a></li>
        </ul>
    </aside>

    <div class="main-wrapper">
        
        <header class="header">
            <div class="header-brand-mobile">Planora</div>
            <a href="logout.php" class="logout-btn">
                <i class="fa-solid fa-right-from-bracket"></i> Logout
            </a>
        </header>

        <main class="content">
            
            <div class="top-grid">
                
                <div class="card">
                    <h3 class="card-title">
                        <i class="fa-regular fa-folder-open" style="color: #6366F1;"></i> Budget Overview
                    </h3>
                    <div class="budget-metric">Total Budget: <strong>$10,000</strong></div>
                    <div class="budget-metric">Committed: <strong>$5,000</strong></div>
                    <div class="budget-metric">Available: <strong>$5,000</strong> <span class="status-good">(Good)</span></div>
                    
                    <div class="progress-bar-container">
                        <div class="progress-bar-fill"></div>
                    </div>
                </div>

                <div class="card">
                    <h3 class="card-title">
                        <i class="fa-solid fa-bolt" style="color: #6366F1;"></i> Quick Actions
                    </h3>
                    <div class="action-buttons">
                        <button class="btn btn-primary">
                            <i class="fa-solid fa-circle-plus"></i> Create Event
                        </button>
                        <button class="btn btn-secondary">
                            <i class="fa-solid fa-magnifying-glass"></i> Browse Vendors
                        </button>
                    </div>
                </div>

            </div>

            <div class="card">
                <h3 class="card-title">
                    <i class="fa-regular fa-calendar-check" style="color: #6366F1;"></i> Upcoming Events
                </h3>
                <div class="list-container">
                    <?php for($i=0; $i<3; $i++): ?>
                    <a href="#" class="list-item">
                        <span>Event Name (Date) - Budget: <strong>$X</strong> , Vendors Booked: <strong>Y</strong></span>
                        <i class="fa-solid fa-chevron-right"></i>
                    </a>
                    <?php endfor; ?>
                </div>
            </div>

            <div class="card">
                <h3 class="card-title">
                    <i class="fa-regular fa-list-alt" style="color: #6366F1;"></i> Recent Bookings
                </h3>
                <div class="list-container">
                    
                    <a href="#" class="list-item">
                        <span>Vendor Name - Service (Status: <span class="status-badge confirmed">Confirmed</span>)</span>
                        <i class="fa-solid fa-chevron-right"></i>
                    </a>

                    <a href="#" class="list-item">
                        <span>Vendor Name - Service (Status: <span class="status-badge pending">Pending</span>)</span>
                        <i class="fa-solid fa-chevron-right"></i>
                    </a>

                    <a href="#" class="list-item">
                        <span>Vendor Name - Service (Status: <span class="status-badge confirmed">Confirmed</span>)</span>
                        <i class="fa-solid fa-chevron-right"></i>
                    </a>

                </div>
            </div>

        </main>
    </div>

    <nav class="bottom-nav">
        <a href="#" class="bottom-nav-item active">
            <i class="fa-solid fa-house"></i>
            <span>Home</span>
        </a>
        <a href="#" class="bottom-nav-item">
            <i class="fa-solid fa-calendar-days"></i>
            <span>Events</span>
        </a>
        <a href="#" class="bottom-nav-item">
            <i class="fa-solid fa-shop"></i>
            <span>Vendors</span>
        </a>
        <a href="#" class="bottom-nav-item">
            <i class="fa-solid fa-book-bookmark"></i>
            <span>Bookings</span>
        </a>
        <a href="logout.php" class="bottom-nav-item">
            <i class="fa-solid fa-right-from-bracket"></i>
            <span>Logout</span>
        </a>
    </nav>

</body>
</html>