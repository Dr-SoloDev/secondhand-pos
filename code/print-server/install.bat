@echo off
chcp 65001 >nul
title Scrap POS - ติดตั้ง Print Server
cd /d "%~dp0"

echo ================================================================
echo   ติดตั้ง Print Server - Scrap POS
echo   เครื่องพิมพความรอน EasyPrint ES-8804 (USB)
echo   ติดตั้งครั้งเดียวตอเครื่อง คอมพิวเตอร Windows ที่เครื่องพิมพตอ
echo ================================================================
echo.

REM ---- 1. ตรวจ Python ----
where py >nul 2>nul
if errorlevel 1 (
    echo [ERROR] ไมพบ Python บนเครื่องนี้
    echo.
    echo วิธีติดตั้ง Python (ครั้งเดียว):
    echo   1. เปด https://www.python.org/downloads/
    echo   2. ดาวนโหลดเวอรชันลาสุด กด Install Now
    echo   3. สำคญ: ตองติ๊ก "Add python.exe to PATH" ดวย
    echo   4. พิมพคำสั่งนี้อีกครั้งเมื่อติดตั้งเสร็จ
    echo.
    pause
    exit /b 1
)
echo [1/5] พบ Python: OK

REM ---- 2. สราง environment + ติดตั้ง dependencies ----
if not exist venv (
    echo [2/5] สราง Python environment (ครั้งแรก ประมาณ 1-2 นาที)...
    py -3 -m venv venv
    if errorlevel 1 (
        echo [ERROR] สราง venv ไมสำเร็จ
        pause
        exit /b 1
    )
) else (
    echo [2/5] พบ environment เดิม ใชตอ...
)

venv\Scripts\python -m pip install --upgrade pip --quiet
venv\Scripts\pip install pillow pywin32 --quiet
if errorlevel 1 (
    echo [ERROR] ติดตั้ง library ไมสำเร็จ - ตรวจอินเทอรเน็ตแลวลองใหม
    pause
    exit /b 1
)
echo       ติดตั้ง pillow + pywin32: OK

REM ---- 3. เลือกเครื่องพิมพ ----
echo.
echo [3/5] เครื่องพิมพที่พบใน Windows เครื่องนี้:
venv\Scripts\python -c "import win32print; [print('   - ' + p[2]) for p in win32print.EnumPrinters(win32print.PRINTER_ENUM_LOCAL | win32print.PRINTER_ENUM_CONNECTIONS)]" 2>nul
echo.
set /p PRINTER_NAME="พิมพชื่อเครื่องพิมพที่ตองการ (Enter = ใช EasyPrint ES-8804): "
if "%PRINTER_NAME%"=="" set PRINTER_NAME=EasyPrint ES-8804
(
    echo PRINTER_NAME=%PRINTER_NAME%
) > print-server.env
echo       ตั้งค่าเครื่องพิมพ: %PRINTER_NAME% - OK

REM ---- 4. ทดสอบพิมพ Test Page ----
echo.
echo [4/5] ทดสอบพิมพ Test Page (รอประมาณ 5 วินาที)...
venv\Scripts\python print_receipt.py --test
if errorlevel 1 (
    echo [ERROR] ทดสอบพิมพไมสำเร็จ - ตรวจวาเครื่องพิมพติดตั้ง driver ถูกตองและพรอมใชงาน
    echo         ถาเครื่องพิมพยังไมพิมพ - ลองแกชื่อเครื่องพิมพในไฟล print-server.env
    pause
    exit /b 1
)
echo       ทดสอบพิมพ: OK - ตรวจวากระดาษออกมาเรียบรอย

REM ---- 5. ตั้งเปดอัตโนมัติเมื่อเปดเครื่อง ----
echo.
echo [5/5] ตั้งให Print Server เริ่มอัตโนมัติเมื่อเปด Windows...
(
    echo ' Scrap POS Print Server - start hidden on boot
    echo Set WshShell = CreateObject^("WScript.Shell"^)
    echo WshShell.CurrentDirectory = "%~dp0"
    echo WshShell.Run "venv\Scripts\pythonw.exe print_server_http.py", 0, False
) > start-hidden.vbs

powershell -NoProfile -ExecutionPolicy Bypass -Command ^
  "$ws = New-Object -ComObject WScript.Shell; $s = $ws.CreateShortcut([Environment]::GetFolderPath('Startup') + '\ScrapPOS-PrintServer.lnk'); $s.TargetPath = 'wscript.exe'; $s.Arguments = '\"%~dp0start-hidden.vbs\"'; $s.Save()" >nul 2>nul
if errorlevel 1 (
    echo       [คำเตือน] ตั้ง auto-start ไมสำเร็จ - เปด start-hidden.vbs เองทุกครั้งที่เปดเครื่อง
) else (
    echo       ตั้ง auto-start: OK
)

REM ---- เริ่ม Print Server ทันที ----
echo.
echo เริ่ม Print Server บนพอร์ต 9120...
start "" /min venv\Scripts\pythonw.exe print_server_http.py
timeout /t 3 /nobreak >nul

venv\Scripts\python -c "import urllib.request,json; r=json.loads(urllib.request.urlopen('http://127.0.0.1:9120/health',timeout=5).read()); print('   printer:',r['printer']); print('   printer_ok:',r['printer_ok']); print('   message:',r['message'])"
echo.
echo ================================================================
echo   ติดตั้งเสร็จเรียบรอย!
echo   - Print Server รนที่พอร์ต 9120 (อัตโนมัติทุกครั้งที่เปดเครื่อง)
echo   - ทดสอบพิมพจากระบบไดที่: เมนูรับซื้อของ - พิมพใบรับซื้อ (ความรอน)
echo.
echo   ขอควรทำตอไป: แจง IP เครองคอมพิวเตอรเครื่องนี้ใหผูดูแลระบบ
echo   (พิมพคำสั่ง: ipconfig  ดูที่ IPv4 Address)
echo ================================================================
pause
