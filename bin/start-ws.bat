@echo off
REM Starts the VolunteerLine WebSocket media server on Windows (XAMPP PHP)
REM Adjust PHP_EXE if XAMPP is installed in a different folder.
set PHP_EXE=D:\xampp\php\php.exe
if not exist "%PHP_EXE%" set PHP_EXE=C:\xampp\php\php.exe
if not exist "%PHP_EXE%" set PHP_EXE=php

"%PHP_EXE%" "%~dp0ws-server.php"
