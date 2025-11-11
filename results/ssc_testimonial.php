<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SSC Testimonial - Final Design</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&family=Playfair+Display:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Montserrat', sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #8a2be2, #4b0082, #1a237e);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        
        .certificate-container {
            width: 297mm;
            height: 210mm;
            background: white;
            margin: 0 auto;
            position: relative;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.4);
            overflow: hidden;
            border-radius: 15px;
            display: flex;
            flex-direction: column;
        }
        
        .border-decoration {
            position: absolute;
            top: 15px;
            left: 15px;
            right: 15px;
            bottom: 15px;
            border: 3px solid #4a148c;
            z-index: 1;
            pointer-events: none;
            border-radius: 10px;
            box-shadow: 0 0 0 10px rgba(255, 215, 0, 0.1);
        }
        
        .border-decoration::before {
            content: "";
            position: absolute;
            top: 8px;
            left: 8px;
            right: 8px;
            bottom: 8px;
            border: 2px solid #0d47a1;
            pointer-events: none;
            border-radius: 5px;
        }
        
        .header {
            background: linear-gradient(135deg, #1a237e, #4a148c, #6a1b9a);
            color: white;
            padding: 15px 40px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: relative;
            border-bottom: 5px solid #ff9800;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
            flex-shrink: 0;
            margin-top: 15px;
        }
        
        .school-info {
            text-align: center;
            flex-grow: 1;
        }
        
        .school-info h1 {
            font-size: 28px; /* make school name stand out */
            margin-bottom: 3px;
            letter-spacing: 1.5px;
            text-shadow: 0 2px 4px rgba(0,0,0,0.25), 0 0 3px rgba(255, 215, 0, 0.6);
            font-family: 'Playfair Display', serif;
            background: linear-gradient(to right, #ffd700, #ffffff);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .school-info p {
            font-size: 14px;
            opacity: 0.9;
        }
        
        .contact-info {
            font-size: 14px;
            margin-top: 3px;
            color: #ffd700;
            text-shadow: 0 0 5px rgba(255, 215, 0, 0.5);
        }
        
        .logo-box {
            width: 80px;
            height: 80px;
            background: transparent;
            border-radius: 0; /* no rounded, no circle */
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            border: none; /* no border on logo */
        }
        .logo-box img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }
        .qr-box {
            width: 80px;
            height: 80px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid #ffffff; /* square border */
            border-radius: 0; /* four-corner squared */
            color: #ffffff;
            font-size: 10px;
            background: rgba(255, 255, 255, 0.08);
        }
        
        .eiin-badge {
            position: absolute;
            bottom: -8px;
            left: 50%;
            transform: translateX(-50%);
            background: linear-gradient(135deg, #ff9800, #ff5722);
            color: white;
            padding: 4px 15px;
            border-radius: 15px;
            font-weight: bold;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
            z-index: 2;
            font-size: 11px;
        }
        
        .main-content {
            padding: 15px 40px;
            position: relative;
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        
        .title-section {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .testimonial-title {
            text-align: center;
            flex-grow: 1;
            position: absolute;
            left: 50%;
            transform: translateX(-50%);
        }
        
        .testimonial-title span {
            display: inline-block;
            padding: 6px 25px;
            background: linear-gradient(135deg, #4a148c, #1a237e);
            color: white;
            font-weight: bold;
            font-size: 20px;
            border-radius: 30px;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15);
            position: relative;
            overflow: hidden;
            font-family: 'Playfair Display', serif;
            text-shadow: 0 0 10px rgba(255, 255, 255, 0.3);
        }
        /* Add slight space before the word TESTIMONIAL visually */
        .testimonial-title span::before {
            content: "";
            display: inline-block;
            width: 6px;
        }
        
        .ref-session {
            display: flex;
            flex-direction: column; /* stack into two lines */
            align-items: flex-end;  /* align to right margin */
            text-align: right;
            gap: 4px;
            font-size: 15px;
            margin-left: auto;
        }
        
        .student-name {
            text-align: center;
            margin: 6px 0; /* tighter spacing around the name block */
            position: relative;
        }
        
        .student-name h3 {
            font-size: 22px;
            color: #1a237e;
            margin-bottom: 3px;
            text-decoration: underline;
            text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.1);
            font-family: 'Playfair Display', serif;
            background: linear-gradient(to right, #1a237e, #4a148c);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .student-name p {
            color: #7f8c8d;
            font-style: italic;
            font-size: 14px;
            margin: 4px 0; /* reduce extra space above/below 'Daughter of' */
        }
        
        .content {
            line-height: 1.6; /* base line-height */
            font-size: 15px;
            color: #333;
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        
        .content p {
            margin-top: 0;         /* remove default top margin */
            margin-bottom: 2px;   /* extra spacing between paragraphs */
            text-align: justify;
            line-height: 1.8;      /* ensure lines do not touch */
        }
        /* Reduce gap between intro line and student name */
        .intro-line { margin-bottom: 4px !important; }
        /* Reduce gap between student name block and the following paragraph */
        .student-name + p { margin-top: 4px !important; }
        
        .highlight {
            font-weight: 700;
            color: #0d47a1;
            background: linear-gradient(to bottom, rgba(13, 71, 161, 0.12), rgba(13, 71, 161, 0.08));
            padding: 2px 5px;
            border-radius: 3px;
            position: relative;
            text-shadow: 0 0 1px rgba(26, 35, 126, 0.2);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }
        
        .statement-box {
            background: linear-gradient(to right, #f1f8ff, #e3f2fd);
            padding: 12px;
            border-radius: 8px;
            margin: 12px 0;
            border-left: 6px solid #ff9800;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.08);
            position: relative;
            overflow: hidden;
        }
        
        .statement-box::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            width: 8px;
            height: 100%;
            background: linear-gradient(to bottom, #ff9800, #ff5722);
        }
        
        .wishes {
            text-align: center;
            font-style: italic;
            font-size: 15px;
            margin: 12px 0;
            color: #1a237e;
            position: relative;
            background: linear-gradient(to right, #1a237e, #4a148c);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .wishes::before, .wishes::after {
            content: "★";
            color: #ff9800;
            margin: 0 8px;
            text-shadow: 0 0 5px rgba(255, 152, 0, 0.5);
        }
        
        .signature-area {
            display: flex;
            justify-content: space-between;
            margin-top: 5px; /* move area slightly upward to create more space below */
            padding-top: 12px;
            border-top: none;
        }
        
        .signature-box {
            text-align: center;
            position: relative;
            width: auto;          /* shrink to content */
            display: inline-block; /* allow intrinsic width */
            margin-right: 12mm;    /* add extra right-side space from page edge */
        }
        
        .signature-box p {
            margin-top: 6px;
            color: #5d6d7e;
            font-weight: 600;
            font-size: 14px;
            text-shadow: 0 0 1px rgba(0, 0, 0, 0.1);
        }
        
        /* Hide the separate line and draw a line matched to the text width */
        .signature-line { display: none; }
        .signature-box p {
            position: relative;
            display: inline-block; /* width equals text width */
        }
        .signature-box p::before {
            content: "";
            position: absolute;
            left: 0;
            right: 0;
            top: -12px;            /* distance above the text */
            height: 1.5px;
            background: #7f8c8d;
        }
        
        .seal-space {
            height: 110px; /* Increased space below Head Teacher */
            width: 100%;
        }
        
        .date {
            text-align: right;
            margin-top: 12px;
            font-weight: 600;
            color: #0d47a1;
            font-size: 14px;
            text-shadow: 0 0 1px rgba(26, 35, 126, 0.2);
        }
        
        .watermark-img {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: 50% 85%; /* push image content lower */
            opacity: 0.50; /* slightly reduce visibility */
            z-index: 0;
            pointer-events: none;
        }
        
        .print-btn {
            position: fixed;
            bottom: 30px;
            right: 30px;
            padding: 12px 25px;
            background: linear-gradient(135deg, #4a148c, #1a237e);
            color: white;
            border: none;
            border-radius: 30px;
            cursor: pointer;
            font-size: 16px;
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.3);
            transition: all 0.3s ease;
            z-index: 100;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .print-btn:hover {
            background: linear-gradient(135deg, #ff9800, #ff5722);
            transform: translateY(-5px);
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.4);
        }
        
        .glossy-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, 
                rgba(255,255,255,0.1) 0%, 
                rgba(255,255,255,0) 20%, 
                rgba(255,255,255,0.1) 40%, 
                rgba(255,255,255,0.2) 60%, 
                rgba(255,255,255,0.1) 80%, 
                rgba(255,255,255,0) 100%);
            pointer-events: none;
            z-index: 2;
            mix-blend-mode: overlay;
        }
        
        /* Ensure full A4 page at 100% with 0.5in margins */
        @page {
            size: A4 landscape;
            margin: 0.5in;
        }

        @media print {
            body {
                background: white;
                margin: 0;
                padding: 0;
            }
            
            .certificate-container {
                box-shadow: none;
                border-radius: 0;
                margin: 0;
                width: 271.6mm; /* 297 - (0.5in * 2) = 297 - 25.4 = 271.6mm */
                height: 184.6mm; /* 210 - (0.5in * 2) = 210 - 25.4 = 184.6mm */
                page-break-inside: avoid;
                break-inside: avoid;
                overflow: hidden;
            }
            
            .print-btn {
                display: none;
            }
            
            /* Enhance glossy effect for print */
            .school-info h1, .student-name h3, .wishes, .highlight {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            /* Watermark lower and slightly fainter for print */
            .watermark-img {
                object-position: 50% 85%;
                opacity: 0.50;
            }
            
            .glossy-overlay {
                display: block;
            }
        }
    </style>
</head>
<body>
    <div class="certificate-container">
        <div class="border-decoration"></div>
        <img class="watermark-img" src="../assets/testimonial_bg.png" alt="Testimonial Background" />
        <div class="glossy-overlay"></div>
        
        <div class="header">
            <div class="logo-box"><img src="../assets/logo.png" alt="School Logo"></div>
            <div class="school-info">
                <h1>JOREPUKURIA SECONDARY SCHOOL</h1>
                <p>Gangni, Meherpur</p>
                <div class="contact-info">Phone: +8801713913076, +8801309118213, Email: info@jorepukuriass.edu.bd</div>
            </div>
            <div class="qr-box">QR CODE</div>
            <div class="eiin-badge">EIIN: 118213</div>
        </div>
        
        <div class="main-content">
            <div class="title-section">
                <div class="testimonial-title">
                    <span>TESTIMONIAL</span>
                </div>
                <div class="ref-session">
                    <div>Ref. No: <strong>tes-0383</strong></div>
                    <div>Session: <strong>2022-2023</strong></div>
                </div>
            </div>
            
            <div class="content">
                <div>
                    <p class="intro-line" style="font-style: italic; text-align: center;">This is to certify that</p>
                    
                    <div class="student-name">
                        <h3>MST. SRABONI KHATUN</h3>
                        <p>Daughter of</p>
                    </div>
                    
                    <p>
                        <span class="highlight">Mr. Selim Reza</span> and <span class="highlight">Mrs. Arshida Khatun</span>
                        of village <span class="highlight">Kholishakundi</span>, post office <span class="highlight">Kholishakundi</span>,
                        Upazila <span class="highlight">Daulatpur</span>, District <span class="highlight">Kushtia</span>,
                        passed the Secondary School Certificate Examination in <span class="highlight">2024</span> from this school under the
                        Board of Intermediate and Secondary Education, <span class="highlight">Jashore</span>
                        bearing Roll No. <span class="highlight">414149</span> and Registration No. <span class="highlight">2113268216</span>,
                        and obtained GPA <span class="highlight">3.17</span> out of 5.00 in <span class="highlight">Business Studies</span>.
                        Date of birth is <span class="highlight">01/01/2008</span>.
                    </p>
                    
                    <div class="statement-box">
                        <p>
                            To the best of my knowledge, she did not take part in any illegal activities of the state or discipline.
                            Her conduct and character are good.
                        </p>
                    </div>
                    
                    <p class="wishes">
                        I wish her every success in life.
                    </p>
                </div>
                
                <div class="signature-area">
                    <div class="date">Date of Issue: 24/06/2024</div>
                    <div class="signature-box">
                        <div class="signature-line"></div>
                        <p>Head Teacher</p>
                        <div class="seal-space"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <button class="print-btn" onclick="window.print()">
        <i class="fas fa-print"></i> Print Testimonial
    </button>
</body>
</html>