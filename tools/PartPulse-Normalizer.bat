@echo off
setlocal EnableExtensions
title PartPulse Normalizer
rem ---------------------------------------------------------------------
rem  Drag the PartPulse .sql export onto this file (or double-click and
rem  paste the path). A sanitized partpulse-normalized-YYYY-MM-DD.zip is
rem  written next to the .sql file. Upload that ZIP in the admin page.
rem ---------------------------------------------------------------------
set "PHP="
where php >nul 2>nul && set "PHP=php"
if not defined PHP if exist "C:\xampp\php\php.exe" set "PHP=C:\xampp\php\php.exe"
if not defined PHP if exist "C:\php\php.exe" set "PHP=C:\php\php.exe"
if not defined PHP if exist "%ProgramFiles%\PHP\php.exe" set "PHP=%ProgramFiles%\PHP\php.exe"
if not defined PHP (
  echo PHP was not found. Install XAMPP or PHP 8.2+ and try again.
  pause
  exit /b 1
)
set "SQL=%~1"
if "%SQL%"=="" (
  set /p "SQL=Drag the .sql export into this window and press Enter: "
)
set "SQL=%SQL:"=%"
if not exist "%SQL%" (
  echo File not found: %SQL%
  pause
  exit /b 1
)
"%PHP%" -d extension=zip "%~dp0normalize.php" "%SQL%" %2 %3 %4
if errorlevel 1 (
  echo.
  echo Normalization failed. See the message above.
) else (
  echo Done. Upload the ZIP shown above in the PartPulse admin page.
)
pause
