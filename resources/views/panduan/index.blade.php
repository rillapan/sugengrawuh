@extends('layouts.app')

@push('styles')
<style>
    /* Make sidebar fixed */
    #wrapper {
        display: flex;
        width: 100%;
        overflow-x: hidden;
    }

    #sidebar-wrapper {
        min-height: 100vh;
        width: 250px;
        position: fixed;
        top: 0;
        left: 0;
        z-index: 1000;
        overflow-y: auto;
        background: #4e73df;
        transition: all 0.3s;
    }

    #content-wrapper {
        width: calc(100% - 250px);
        min-height: 100vh;
        margin-left: 250px;
        transition: all 0.3s;
    }

    /* Adjust main content padding */
    .container-fluid {
        padding: 20px;
        margin-top: 70px; /* Space for navbar */
    }

    /* Responsive adjustments */
    @media (max-width: 768px) {
        #sidebar-wrapper {
            margin-left: -250px;
        }
        #content-wrapper {
            width: 100%;
            margin-left: 0;
        }
        #sidebar-wrapper.active {
            margin-left: 0;
        }
        #content-wrapper.active {
            margin-left: 250px;
            width: calc(100% - 250px);
        }
    }
</style>
@endpush

@section('content')
<div class="container-fluid">
    <!-- Page Heading -->
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">Panduan Penggunaan</h1>
    </div>
    <style>
        :root {
            --primary: #2c3e50;
            --secondary: #3498db;
            --accent: #e74c3c;
            --light: #ecf0f1;
            --dark: #2c3e50;
            --success: #2ecc71;
            --warning: #f39c12;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background-color: #f5f7fa;
            color: #333;
            line-height: 1.6;
        }
        
        header {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            padding: 2rem 0;
            text-align: center;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        
        .container {
            width: 90%;
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem 0;
        }
        
        h1 {
            font-size: 2.5rem;
            margin-bottom: 1rem;
        }
        
        h2 {
            color: var(--primary);
            margin: 2rem 0 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid var(--secondary);
        }
        
        h3 {
            color: var(--secondary);
            margin: 1.5rem 0 0.5rem;
        }
        
        .intro {
            background-color: white;
            border-radius: 8px;
            padding: 2rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            margin-bottom: 2rem;
        }
        
        .feature-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            margin: 2rem 0;
        }
        
        .feature-card {
            background-color: white;
            border-radius: 8px;
            padding: 1.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .feature-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 15px rgba(0, 0, 0, 0.1);
        }
        
        .feature-card h3 {
            color: var(--primary);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .feature-card h3 i {
            color: var(--secondary);
        }
        
        .steps {
            background-color: white;
            border-radius: 8px;
            padding: 2rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            margin-bottom: 2rem;
        }
        
        .step {
            margin-bottom: 1.5rem;
            padding-left: 1rem;
            border-left: 4px solid var(--secondary);
        }
        
        .step h3 {
            margin-top: 0;
        }
        
        .transaction-types {
            display: flex;
            flex-wrap: wrap;
            gap: 1.5rem;
            margin: 1.5rem 0;
        }
        
        .transaction-card {
            flex: 1;
            min-width: 300px;
            background-color: white;
            border-radius: 8px;
            padding: 1.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
        }
        
        .transaction-card h3 {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .highlight {
            background-color: var(--light);
            padding: 1rem;
            border-radius: 6px;
            margin: 1rem 0;
            border-left: 4px solid var(--secondary);
        }
        
        footer {
            background-color: var(--dark);
            color: white;
            text-align: center;
            padding: 2rem 0;
            margin-top: 3rem;
        }
        
        @media (max-width: 768px) {
            .feature-grid {
                grid-template-columns: 1fr;
            }
            
            .transaction-types {
                flex-direction: column;
            }
        }
    </style>
    <link rel="stylesheet" href="{{ asset('vendor/fontawesome-free/css/all.min.css') }}">
    <header>
        <div class="container">
            <h1><i class="fas fa-gamepad"></i> Panduan Sistem Rental PlayStation</h1>
            <p>Website Manajemen untuk Bisnis Rental PlayStation</p>
        </div>
    </header>
    
    <div class="container">
        <section class="intro">
            <h2>Pengenalan Website</h2>
            <p>Website ini merupakan sistem manajemen untuk bisnis rental PlayStation yang dirancang agar proses pengelolaan menjadi lebih mudah, cepat, dan terorganisir. Sistem ini menyediakan fitur lengkap mulai dari pengelolaan perangkat, manajemen F&B, transaksi rental, hingga laporan keuangan dan statistik.</p>
            
            <div class="highlight">
                <h3>Tujuan</h3>
                <p>Website ini bertujuan untuk membantu admin dalam:</p>
                <ul>
                    <li>Mengelola seluruh perangkat PlayStation dengan lebih efisien.</li>
                    <li>Mengatur menu F&B beserta stoknya.</li>
                    <li>Melakukan transaksi rental dengan sistem kasir yang khusus dirancang untuk rental PlayStation.</li>
                    <li>Melihat laporan pendapatan dan aktivitas operasional secara detail dan akurat.</li>
                </ul>
            </div>
        </section>
        
        <section class="features">
            <h2>Deskripsi Fitur</h2>
            <p>Admin dapat melakukan berbagai aktivitas manajemen melalui sistem ini:</p>
            
            <div class="feature-grid">
                <div class="feature-card">
                    <h3><i class="fas fa-tv"></i> Kelola Data Perangkat</h3>
                    <p>Mengelola semua perangkat PlayStation yang tersedia untuk rental, termasuk status dan informasi detailnya.</p>
                </div>
                
                <div class="feature-card">
                    <h3><i class="fas fa-utensils"></i> Manajemen F&B</h3>
                    <p>Mengelola menu makanan dan minuman, stok barang, serta laporan penjualan F&B.</p>
                </div>
                
                <div class="feature-card">
                    <h3><i class="fas fa-cash-register"></i> Transaksi Rental</h3>
                    <p>Melakukan transaksi rental dengan sistem kasir khusus untuk PlayStation.</p>
                </div>
                
                <div class="feature-card">
                    <h3><i class="fas fa-chart-line"></i> Laporan & Statistik</h3>
                    <p>Melihat laporan pendapatan, statistik operasional, dan mengekspor data.</p>
                </div>
            </div>
        </section>
        
        <section class="steps">
            <h2>Cara Penggunaan</h2>
            
            <div class="step">
                <h3>1. Kelola Data Perangkat</h3>
                <p>Mengelola semua informasi terkait perangkat PlayStation yang tersedia.</p>
                
                <h4>a) Buat Jenis PlayStation</h4>
                <ul>
                    <li>Admin dapat menambahkan jenis-jenis konsol PlayStation seperti PS1, PS2, PS3, PS4 (termasuk Slim/Pro), dan PS5 (Disc/Digital).</li>
                    <li>Admin juga dapat menetapkan harga paket berdasarkan durasi bermain setiap jenis perangkat.</li>
                </ul>
                
                <h4>b) Data Perangkat</h4>
                <ul>
                    <li>Pada menu ini, admin menambahkan perangkat fisik yang digunakan untuk rental berdasarkan jenis PlayStation yang tersedia.</li>
                </ul>
            </div>
            
            <div class="step">
                <h3>2. Kelola F&B</h3>
                <p>Mengelola menu makanan dan minuman serta stok barang.</p>
                
                <h4>a) Kelola Barang</h4>
                <ul>
                    <li>Admin dapat melakukan CRUD (Create, Read, Update, Delete) untuk menu makanan dan minuman.</li>
                </ul>
                
                <h4>b) Manajemen Stok</h4>
                <ul>
                    <li>Fitur untuk menambah atau mengurangi stok barang, termasuk mencatat mutasi stok.</li>
                </ul>
                
                <h4>c) Laporan Penjualan</h4>
                <ul>
                    <li>Menampilkan laporan penjualan berdasarkan periode tanggal tertentu serta distribusi penjualan produk.</li>
                </ul>
            </div>
            
            <div class="step">
                <h3>3. Transaksi</h3>
                <p>Melakukan transaksi rental PlayStation dengan dua jenis transaksi yang tersedia.</p>
                
                <div class="transaction-types">
                    <div class="transaction-card">
                        <h3><i class="fas fa-box"></i> Transaksi Paket</h3>
                        <ul>
                            <li>Pelanggan menyewa perangkat berdasarkan paket waktu yang telah ditentukan pada data jenis perangkat.</li>
                            <li>Perangkat yang dibooking akan berstatus "Digunakan".</li>
                            <li>Setelah waktu bermain habis, status akan otomatis kembali menjadi "Tersedia".</li>
                            <li>Timer akan menghitung mundur sesuai durasi paket.</li>
                            <li>Pembayaran dapat dilakukan di awal atau di akhir.</li>
                        </ul>
                    </div>
                    
                    <div class="transaction-card">
                        <h3><i class="fas fa-clock"></i> Transaksi Lost Time</h3>
                        <ul>
                            <li>Pelanggan bermain tanpa batasan paket waktu.</li>
                             <li>Perangkat yang dibooking akan berstatus "Digunakan".</li>
                            <li>Setelah waktu bermain habis, status akan otomatis kembali menjadi "Tersedia".</li>
                            <li>Timer akan menghitung total durasi bermain.</li>
                            <li>Pembayaran dilakukan di akhir setelah permainan selesai.</li>
                        </ul>
                    </div>
                </div>
            </div>
            
            <div class="step">
                <h3>4. Laporan</h3>
                <p>Fitur laporan menampilkan pendapatan berdasarkan periode tanggal yang dipilih, dilengkapi grafik statistik.</p>
                <p>Admin juga dapat mengekspor laporan dalam format PDF dan Excel untuk keperluan analisis lebih lanjut.</p>
            </div>
        </section>
    </div>
    
    
</div>
@endsection