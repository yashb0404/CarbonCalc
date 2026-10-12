@echo off
title Carbon Calc - Database Setup
echo Make sure Apache and MySQL are STARTED in XAMPP Control Panel.
echo Importing database...
"C:\xampp\mysql\bin\mysql.exe" -u root < "%~dp0database\carbon_calc.sql"
if errorlevel 1 (echo FAILED - start MySQL in XAMPP and run this again. & pause & exit /b)
start http://localhost/carbon_calc
start http://localhost/phpmyadmin
echo DONE. Login: yash@demo.com / demo123   Admin: admin@demo.com / admin123
pause
