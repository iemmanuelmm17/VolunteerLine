@echo off
REM Starts the VolunteerLine WebSocket media server on Windows (PHP 8.2+)
REM Checks C:\PHP\php.exe first (IIS PHP 8.5), then XAMPP paths.
set PHP_EXE=
if exist "C:\PHP\php.exe" set PHP_EXE=C:\PHP\php.exe
if not defined PHP_EXE if exist "D:\PHP\php.exe" set PHP_EXE=D:\PHP\php.exe
if not defined PHP_EXE if exist "D:\xampp\php\php.exe" set PHP_EXE=D:\xampp\php\php.exe
if not defined PHP_EXE if exist "C:\xampp\php\php.exe" set PHP_EXE=C:\xampp\php\php.exe
if not defined PHP_EXE set PHP_EXE=php

"%PHP_EXE%" "%~dp0ws-server.php"
