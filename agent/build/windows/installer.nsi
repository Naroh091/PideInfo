; PideInfo Agent — NSIS Installer Script
; Build: makensis /DVERSION=1.0.0 /DDIST_DIR="dist\PideInfo Agent" /DOUTFILE=PideInfo-Agent-windows-x64-setup.exe installer.nsi

!define APP_NAME     "PideInfo Agent"
!define APP_EXE      "pideinfo-agent.exe"
!define PUBLISHER    "PideInfo"
!define REG_KEY      "Software\Microsoft\Windows\CurrentVersion\Uninstall\PideInfoAgent"

; Defaults (overridden by /D flags from CI)
!ifndef VERSION
  !define VERSION "1.0.0"
!endif
!ifndef DIST_DIR
  !define DIST_DIR "dist\PideInfo Agent"
!endif
!ifndef OUTFILE
  !define OUTFILE "PideInfo-Agent-windows-x64-setup.exe"
!endif

Name "${APP_NAME} ${VERSION}"
OutFile "${OUTFILE}"
InstallDir "$PROGRAMFILES64\${APP_NAME}"
InstallDirRegKey HKLM "${REG_KEY}" "InstallLocation"
RequestExecutionLevel admin
SetCompressor /SOLID lzma

; Modern UI
!include "MUI2.nsh"
!define MUI_ABORTWARNING
!insertmacro MUI_PAGE_WELCOME
!insertmacro MUI_PAGE_DIRECTORY
!insertmacro MUI_PAGE_INSTFILES
!insertmacro MUI_PAGE_FINISH
!insertmacro MUI_UNPAGE_CONFIRM
!insertmacro MUI_UNPAGE_INSTFILES
!insertmacro MUI_LANGUAGE "Spanish"

Section "Install"
  SetOutPath "$INSTDIR"
  File /r "${DIST_DIR}\*.*"

  ; Start Menu shortcut
  CreateDirectory "$SMPROGRAMS\${APP_NAME}"
  CreateShortcut "$SMPROGRAMS\${APP_NAME}\${APP_NAME}.lnk" \
    "$INSTDIR\${APP_EXE}" "--tray" "$INSTDIR\${APP_EXE}" 0

  ; Desktop shortcut (optional)
  CreateShortcut "$DESKTOP\${APP_NAME}.lnk" \
    "$INSTDIR\${APP_EXE}" "--tray" "$INSTDIR\${APP_EXE}" 0

  ; Add to startup
  WriteRegStr HKCU \
    "Software\Microsoft\Windows\CurrentVersion\Run" \
    "${APP_NAME}" \
    '"$INSTDIR\${APP_EXE}" --tray'

  ; Register uninstaller
  WriteRegStr   HKLM "${REG_KEY}" "DisplayName"          "${APP_NAME}"
  WriteRegStr   HKLM "${REG_KEY}" "DisplayVersion"       "${VERSION}"
  WriteRegStr   HKLM "${REG_KEY}" "Publisher"            "${PUBLISHER}"
  WriteRegStr   HKLM "${REG_KEY}" "InstallLocation"      "$INSTDIR"
  WriteRegStr   HKLM "${REG_KEY}" "UninstallString"      "$INSTDIR\uninstall.exe"
  WriteRegDWORD HKLM "${REG_KEY}" "NoModify"             1
  WriteRegDWORD HKLM "${REG_KEY}" "NoRepair"             1
  WriteUninstaller "$INSTDIR\uninstall.exe"
SectionEnd

Section "Uninstall"
  ; Remove startup entry
  DeleteRegValue HKCU \
    "Software\Microsoft\Windows\CurrentVersion\Run" \
    "${APP_NAME}"

  ; Remove files
  RMDir /r "$INSTDIR"

  ; Remove shortcuts
  Delete "$SMPROGRAMS\${APP_NAME}\${APP_NAME}.lnk"
  RMDir  "$SMPROGRAMS\${APP_NAME}"
  Delete "$DESKTOP\${APP_NAME}.lnk"

  ; Remove registry key
  DeleteRegKey HKLM "${REG_KEY}"
SectionEnd
