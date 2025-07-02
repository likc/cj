#!/usr/bin/env python3
# -*- coding: utf-8 -*-

import os
import sys
import time
import json
import shutil
import zipfile
import tempfile
import subprocess
import winreg
import requests
import uuid
import base64
import platform
import socket
import re
from pathlib import Path
from urllib.parse import urljoin

class MacAddressDetector:
    """Detector robusto de MAC Address"""
    
    def __init__(self, debug_mode=False):
        self.debug_mode = debug_mode
        self.detected_mac = None
        self.detection_method = None
    
    def _log(self, message):
        if self.debug_mode:
            print(f"[MAC DEBUG] {message}")
    
    def validate_mac_format(self, mac_str):
        """Valida formato de MAC"""
        if not mac_str:
            return False
        
        mac_clean = mac_str.strip().upper()
        
        # Padrão de MAC válido
        if re.match(r'^([0-9A-F]{2}[:-]){5}([0-9A-F]{2})$', mac_clean):
            # Normalizar para formato XX:XX:XX:XX:XX:XX
            mac_normalized = mac_clean.replace('-', ':')
            
            # Verificar se não é MAC inválido
            invalid_macs = ['00:00:00:00:00:00', 'FF:FF:FF:FF:FF:FF']
            if mac_normalized not in invalid_macs:
                return mac_normalized
        
        return False
    
    def method_uuid_getnode(self):
        """Método UUID getnode"""
        try:
            self._log("Tentando uuid.getnode()...")
            mac_int = uuid.getnode()
            
            if mac_int != 0 and mac_int != 0xffffffffffff:
                mac_hex = f"{mac_int:012x}"
                mac_formatted = ':'.join([mac_hex[i:i+2] for i in range(0, 12, 2)]).upper()
                validated = self.validate_mac_format(mac_formatted)
                if validated:
                    self._log(f"MAC detectado via uuid.getnode(): {validated}")
                    return validated
            
            return None
        except Exception as e:
            self._log(f"Erro uuid.getnode(): {e}")
            return None
    
    def method_getmac_command(self):
        """Comando getmac do Windows"""
        if platform.system() != 'Windows':
            return None
        
        try:
            self._log("Tentando comando getmac...")
            result = subprocess.run(['getmac', '/format', 'list'], 
                                  capture_output=True, text=True, timeout=10)
            
            if result.returncode == 0:
                # Procurar MACs na saída
                mac_pattern = r'([0-9A-F]{2}[:-]){5}[0-9A-F]{2}'
                matches = re.findall(mac_pattern, result.stdout.upper())
                
                for match in matches:
                    validated = self.validate_mac_format(match)
                    if validated:
                        self._log(f"MAC detectado via getmac: {validated}")
                        return validated
            
            return None
        except Exception as e:
            self._log(f"Erro getmac: {e}")
            return None
    
    def method_wmic(self):
        """Comando WMIC"""
        if platform.system() != 'Windows':
            return None
        
        try:
            self._log("Tentando comando wmic...")
            result = subprocess.run([
                'wmic', 'path', 'Win32_NetworkAdapter', 
                'where', 'NetEnabled=true', 
                'get', 'MACAddress', '/format:list'
            ], capture_output=True, text=True, timeout=10)
            
            if result.returncode == 0:
                lines = result.stdout.split('\n')
                for line in lines:
                    if 'MACAddress=' in line and '=' in line:
                        mac_part = line.split('=', 1)[1].strip()
                        validated = self.validate_mac_format(mac_part)
                        if validated:
                            self._log(f"MAC detectado via wmic: {validated}")
                            return validated
            
            return None
        except Exception as e:
            self._log(f"Erro wmic: {e}")
            return None
    
    def detect_mac_address(self):
        """Método principal de detecção"""
        self._log("Iniciando detecção de MAC...")
        
        methods = [
            ("UUID GetNode", self.method_uuid_getnode),
            ("GetMac Command", self.method_getmac_command),
            ("WMIC", self.method_wmic),
        ]
        
        for method_name, method_func in methods:
            try:
                result = method_func()
                if result:
                    self.detected_mac = result
                    self.detection_method = method_name
                    self._log(f"✅ MAC detectado com sucesso: {result} (via {method_name})")
                    return result
            except Exception as e:
                self._log(f"Erro no método {method_name}: {e}")
        
        self._log("❌ Falha na detecção de MAC")
        return None

class CompreJogosConfig:
    """Configurações do sistema"""
    _BASE_URL = "aHR0cHM6Ly9saWtjLm5ldC9jb21wcmVqb2dvcy8="
    _LOGIN_ENDPOINT = "YXBpL2xvZ2luX3NpbXBsZS5waHA="
    _VALIDATE_ENDPOINT = "YXBpL3ZhbGlkYXRlLnBocA=="
    
    CONFIG_FILE = "config.dat"
    LOCAL_ZIP = "COMPREJOGOS.zip"
    VERSION = "2.3.0"
    
    @classmethod
    def get_base_url(cls):
        return base64.b64decode(cls._BASE_URL).decode('utf-8')
    
    @classmethod
    def get_login_endpoint(cls):
        return base64.b64decode(cls._LOGIN_ENDPOINT).decode('utf-8')
    
    @classmethod
    def get_validate_endpoint(cls):
        return base64.b64decode(cls._VALIDATE_ENDPOINT).decode('utf-8')

class CompreJogosInstaller:
    def __init__(self):
        self.config = CompreJogosConfig()
        self.steam_exe = None
        self.steam_dir = None
        self.config_dir = None
        self.ESC = '\033'
        self.user_data = {}
        self.session = requests.Session()
        self.debug_mode = self._check_debug_mode()
        self.current_mac = None
        self.mac_detector = MacAddressDetector(self.debug_mode)
        
        self.load_user_config()
        self.setup_steam_paths()
        self._detect_mac_with_retry()
    
    def _check_debug_mode(self):
        return os.path.exists("debug.txt") or "--debug" in sys.argv
    
    def _log(self, message, force=False):
        if self.debug_mode or force:
            print(f"[DEBUG] {message}")
    
    def _detect_mac_with_retry(self):
        """Detecta MAC com várias tentativas"""
        self._log("Iniciando detecção de MAC com retry...")
        
        max_attempts = 3
        for attempt in range(max_attempts):
            self._log(f"Tentativa {attempt + 1} de {max_attempts}")
            
            mac = self.mac_detector.detect_mac_address()
            if mac:
                self.current_mac = mac
                self._log(f"MAC detectado com sucesso: {mac}")
                return
            
            if attempt < max_attempts - 1:
                self._log("Aguardando antes da próxima tentativa...")
                time.sleep(2)
        
        # Último recurso: tentar método manual
        self._log("Tentando método manual de detecção...")
        try:
            # Método alternativo usando subprocess diretamente
            if platform.system() == 'Windows':
                result = subprocess.run(['getmac'], capture_output=True, text=True, timeout=5)
                if result.returncode == 0:
                    # Procurar primeiro MAC na saída
                    lines = result.stdout.split('\n')
                    for line in lines:
                        if line.strip() and not line.startswith('Physical'):
                            parts = line.split()
                            if parts:
                                potential_mac = parts[0].strip()
                                validated = self.mac_detector.validate_mac_format(potential_mac)
                                if validated:
                                    self.current_mac = validated
                                    self._log(f"MAC detectado via método manual: {validated}")
                                    return
        except Exception as e:
            self._log(f"Erro no método manual: {e}")
        
        self._log("❌ ERRO CRÍTICO: Não foi possível detectar MAC address")
    
    def _show_user_message(self, message, message_type="info"):
        """Mostra mensagem para o usuário"""
        icons = {
            "info": "ℹ️",
            "success": "✅",
            "error": "❌",
            "warning": "⚠️",
            "loading": "⏳"
        }
        
        colors = {
            "info": f"{self.ESC}[36m",
            "success": f"{self.ESC}[32m",
            "error": f"{self.ESC}[31m",
            "warning": f"{self.ESC}[33m",
            "loading": f"{self.ESC}[35m"
        }
        
        icon = icons.get(message_type, "ℹ️")
        color = colors.get(message_type, f"{self.ESC}[36m")
        
        print(f"    {color}{icon} {message}{self.ESC}[0m")
    
    def load_user_config(self):
        try:
            if os.path.exists(self.config.CONFIG_FILE):
                with open(self.config.CONFIG_FILE, 'r', encoding='utf-8') as f:
                    self.user_data = json.load(f)
        except Exception:
            self.user_data = {}
    
    def save_user_config(self):
        try:
            with open(self.config.CONFIG_FILE, 'w', encoding='utf-8') as f:
                json.dump(self.user_data, f, indent=2, ensure_ascii=False)
        except Exception as e:
            self._log(f"Erro ao salvar config: {e}")
    
    def validate_mac_consistency(self):
        """Valida consistência do MAC"""
        if not self.current_mac:
            self._log("MAC atual não disponível")
            return False
        
        stored_mac = self.user_data.get('mac_address')
        if not stored_mac:
            return True  # Primeiro uso
        
        if self.current_mac != stored_mac:
            self._log(f"MAC inconsistente: atual={self.current_mac}, armazenado={stored_mac}")
            return False
        
        return True
    
    def validate_client_status(self):
        """Valida status do cliente no servidor"""
        try:
            if not self.user_data.get('session_token') or not self.user_data.get('user_id'):
                self._log("Dados de sessão inválidos")
                return False
            
            if not self.current_mac:
                self._log("MAC não disponível para validação")
                return False
            
            validate_data = {
                'session_token': self.user_data.get('session_token'),
                'user_id': self.user_data.get('user_id'),
                'mac_address': self.current_mac,
                'version': self.config.VERSION
            }
            
            validate_url = urljoin(self.config.get_base_url(), self.config.get_validate_endpoint())
            
            headers = {
                'User-Agent': f'COMPREJOGOS/{self.config.VERSION}',
                'Accept': 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded'
            }
            
            self._log("Validando status do cliente no servidor...")
            response = self.session.post(validate_url, data=validate_data, headers=headers, timeout=15)
            
            if response.status_code == 200:
                result = response.json()
                is_valid = result.get('is_client', False) and result.get('success', False)
                
                if not is_valid:
                    error_msg = result.get('message', 'Status de cliente inválido')
                    error_code = result.get('error_code', '')
                    
                    # Log específico para erros de MAC
                    if error_code in ['MULTIPLE_MACS', 'WRONG_MAC']:
                        self._log(f"VIOLAÇÃO DE MAC detectada: {error_msg}")
                    else:
                        self._log(f"Validação falhou: {error_msg}")
                
                return is_valid
            else:
                self._log(f"Erro HTTP na validação: {response.status_code}")
                return False
            
        except Exception as e:
            self._log(f"Erro na validação: {e}")
            return False
    
    def setup_steam_paths(self):
        """Detecta caminhos da Steam"""
        try:
            with winreg.OpenKey(winreg.HKEY_CURRENT_USER, r"Software\Valve\Steam") as key:
                self.steam_exe, _ = winreg.QueryValueEx(key, "SteamExe")
                self.steam_dir = str(Path(self.steam_exe).parent)
                self.config_dir = os.path.join(self.steam_dir, "config")
                self._log(f"Steam encontrado: {self.steam_dir}")
        except Exception:
            self._show_user_message("Steam não foi encontrado no sistema", "error")
            input("Pressione Enter para sair...")
            sys.exit(1)
    
    def clear_screen(self):
        os.system('cls' if os.name == 'nt' else 'clear')
    
    def create_gradient_color(self, step, total_steps=98):
        base_r, base_g, base_b = 138, 43, 226
        var_r, var_g, var_b = 117, 157, 29
        
        r = base_r + (var_r * step // total_steps)
        g = base_g + (var_g * step // total_steps)
        b = base_b + (var_b * step // total_steps)
        
        return f"{self.ESC}[38;2;{r};{g};{b}m"
    
    def print_gradient_text(self, text, center_padding=0):
        gradient_text = ""
        for i, char in enumerate(text[:98]):
            color = self.create_gradient_color(i)
            gradient_text += f"{color}{char}"
        
        padding = " " * center_padding
        print(f"{padding}{gradient_text}{self.ESC}[0m")
    
    def show_banner(self):
        self.clear_screen()
        
        banner_lines = [
            "",
            "",
            "          ███████╗ ███████╗ ██╗   ██╗ ██████╗  ██████╗  ███████╗     ██╗ ███████╗ ███████╗ ███████╗ ███████╗",
            "          ██╔════╝ ██╔═══██║ ████  ██║ ██╔══██║ ██╔═══██║ ██╔════╝     ██║ ██╔═══██║ ██╔════╝ ██╔═══██║ ██╔════╝",
            "          ██║      ██║   ██║ ██╔████║ ██████╔╝ ██████╔╝ █████╗       ██║ ██║   ██║ ██║ ████ ██║   ██║ ███████╗",
            "          ██║      ██║   ██║ ██║╚██╔╝ ██╔═══╝  ██╔═══██║ ██╔══╝  ██╗  ██║ ██║   ██║ ██║╚═██╗ ██║   ██║      ██║",
            "          ╚███████╗ ╚███████║ ██║ ╚═╝  ██║      ██║   ██║ ███████╗ ╚████╔╝ ╚███████║ ╚███████║ ╚███████║ ███████║",
            "           ╚══════╝  ╚══════╝ ╚═╝      ╚═╝      ╚═╝   ╚═╝ ╚══════╝  ╚═══╝   ╚══════╝  ╚══════╝  ╚══════╝ ╚══════╝"
        ]
        
        for line in banner_lines:
            self.print_gradient_text(line)
    
    def show_login_screen(self):
        self.clear_screen()
        self.show_banner()
        
        print()
        print(f"             {self.ESC}[38;2;255;255;255m╔══════════════════════════════════════╗{self.ESC}[0m")
        print(f"             {self.ESC}[38;2;255;255;255m║            AUTENTICAÇÃO              ║{self.ESC}[0m")
        print(f"             {self.ESC}[38;2;255;255;255m╚══════════════════════════════════════╝{self.ESC}[0m")
        print()
        
        # Mostrar status da detecção de MAC
        if self.current_mac:
            mac_masked = f"{self.current_mac[:8]}***{self.current_mac[-5:]}"
            print(f"             {self.ESC}[38;2;100;255;100m🖥️  Computador: {mac_masked}{self.ESC}[0m")
            print(f"             {self.ESC}[38;2;150;150;150m📡 Método: {self.mac_detector.detection_method or 'N/A'}{self.ESC}[0m")
        else:
            print(f"             {self.ESC}[38;2;255;100;100m❌ Erro: Computador não identificado{self.ESC}[0m")
        
        print()
        
        login = input(f"             {self.ESC}[38;2;100;255;100m➤ Usuário: {self.ESC}[0m").strip()
        senha = input(f"             {self.ESC}[38;2;100;255;100m➤ Senha: {self.ESC}[0m").strip()
        
        return login, senha
    
    def authenticate_user(self, login, senha):
        """Autentica usuário no servidor"""
        try:
            if not self.current_mac:
                self._show_user_message("Erro: Computador não pôde ser identificado", "error")
                self._show_user_message("Tente executar como administrador", "warning")
                return False
            
            self._show_user_message("Verificando credenciais...", "loading")
            
            auth_data = {
                'login': login,
                'senha': senha,
                'mac_address': self.current_mac,
                'version': self.config.VERSION
            }
            
            # Usar API debug se estiver em modo debug
            endpoint = "api/login_debug.php" if self.debug_mode else "api/login_simple.php"
            auth_url = urljoin(self.config.get_base_url(), endpoint)
            
            headers = {
                'User-Agent': f'COMPREJOGOS/{self.config.VERSION}',
                'Accept': 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded'
            }
            
            self._log(f"Enviando login para: {auth_url}")
            self._log(f"MAC usado: {self.current_mac}")
            self._log(f"Endpoint: {endpoint}")
            
            response = self.session.post(auth_url, data=auth_data, headers=headers, timeout=30)
            
            if response.status_code == 200:
                try:
                    result = response.json()
                    
                    # Log da resposta completa em debug
                    if self.debug_mode:
                        self._log(f"Resposta completa: {result}")
                    
                except:
                    self._show_user_message("Erro na comunicação com o servidor", "error")
                    self._log(f"Resposta não-JSON: {response.text[:200]}")
                    return False
                
                if result.get('success'):
                    if not result.get('is_client'):
                        self._show_user_message("Acesso negado. Sua conta não está ativa.", "error")
                        self._show_user_message("Entre em contato com o suporte para ativar sua conta.", "warning")
                        time.sleep(3)
                        return False
                    
                    # Verificar se MAC foi salvo corretamente (modo debug)
                    debug_info = result.get('debug', {})
                    if debug_info and self.debug_mode:
                        mac_saved = debug_info.get('mac_saved')
                        if mac_saved == self.current_mac:
                            self._log(f"✅ MAC salvo corretamente: {mac_saved}")
                        else:
                            self._log(f"⚠️ MAC salvo diferente: esperado={self.current_mac}, salvo={mac_saved}")
                    
                    # Salvar dados do usuário
                    self.user_data = {
                        'login': login,
                        'mac_address': self.current_mac,
                        'authenticated': True,
                        'user_id': result.get('user_id'),
                        'session_token': result.get('session_token'),
                        'is_client': result.get('is_client'),
                        'last_validation': time.time(),
                        'auth_timestamp': time.time(),
                        'detection_method': self.mac_detector.detection_method
                    }
                    self.save_user_config()
                    
                    self._show_user_message("Autenticação realizada com sucesso!", "success")
                    
                    # Mostrar informações adicionais em debug
                    if self.debug_mode and debug_info:
                        self._show_user_message(f"Debug: Sessão ID {debug_info.get('session_id', 'N/A')}", "info")
                        self._show_user_message(f"Debug: Affected rows {debug_info.get('affected_rows', 'N/A')}", "info")
                    
                    time.sleep(1)
                    return True
                else:
                    error_msg = result.get('message', 'Erro de autenticação')
                    error_code = result.get('error_code', '')
                    
                    # Tratar erros específicos de MAC
                    if error_code == 'MAC_ALREADY_LINKED':
                        self._show_user_message("❌ ERRO: Sua conta já está vinculada a outro computador", "error")
                        self._show_user_message(f"   {error_msg}", "warning")
                        print()
                        self._show_user_message("💡 Para usar este computador:", "info")
                        print("   • Entre em contato com o suporte")
                        print("   • Solicite a desvinculação do computador anterior")
                        print("   • Cada conta pode ter apenas 1 computador vinculado")
                        print()
                        input("   Pressione Enter para tentar novamente...")
                        return False
                    elif error_code == 'WRONG_MAC':
                        self._show_user_message("❌ ERRO: Computador não autorizado", "error")
                        self._show_user_message(f"   {error_msg}", "warning")
                        print()
                        self._show_user_message("💡 Soluções:", "info")
                        print("   • Use o computador vinculado à sua conta")
                        print("   • Ou solicite ao suporte para desvincular o computador anterior")
                        print()
                        input("   Pressione Enter para tentar novamente...")
                        return False
                    elif error_code in ['SESSION_CREATE_FAILED', 'SESSION_VERIFICATION_FAILED']:
                        self._show_user_message("❌ ERRO: Problema no servidor", "error")
                        self._show_user_message("   Entre em contato com o suporte técnico", "warning")
                        if self.debug_mode:
                            self._show_user_message(f"   Debug: {error_msg}", "info")
                        time.sleep(2)
                        return False
                    else:
                        self._show_user_message(error_msg, "error")
                        if self.debug_mode and result.get('debug'):
                            self._log(f"Debug info: {result['debug']}")
                        time.sleep(2)
                        return False
            else:
                self._show_user_message(f"Erro de comunicação (HTTP {response.status_code})", "error")
                if self.debug_mode:
                    self._log(f"HTTP {response.status_code}: {response.text[:200]}")
                time.sleep(2)
                return False
                
        except requests.exceptions.RequestException as e:
            self._show_user_message("Erro de conexão. Verifique sua internet.", "error")
            self._log(f"Erro de conexão: {e}")
            time.sleep(2)
            return False
        except Exception as e:
            self._log(f"Erro inesperado: {e}")
            self._show_user_message("Erro inesperado durante a autenticação", "error")
            time.sleep(2)
            return False
    
    def check_authentication(self):
        """Verifica autenticação"""
        if not self.user_data.get('authenticated'):
            self._log("Usuário não autenticado")
            return False
        
        if not self.user_data.get('is_client'):
            self._log("Usuário não é cliente")
            return False
        
        if not self.validate_mac_consistency():
            self._show_user_message("Este computador não está autorizado para esta conta", "error")
            self.user_data['authenticated'] = False
            self.save_user_config()
            return False
        
        # Validação periódica no servidor (a cada 2 minutos)
        last_validation = self.user_data.get('last_validation', 0)
        if time.time() - last_validation > 120:
            self._log("Validando status no servidor...")
            if not self.validate_client_status():
                self._show_user_message("Sua autorização foi revogada ou expirou", "error")
                self.user_data['authenticated'] = False
                self.user_data['is_client'] = False
                self.save_user_config()
                return False
            else:
                self.user_data['last_validation'] = time.time()
                self.save_user_config()
                self._log("Validação bem-sucedida")
        
        return True
    
    def show_menu(self):
        """Exibe menu principal"""
        menu_items = [
            "╔═══════════════════════════════════════════════════════════════╗",
            "║                         MENU PRINCIPAL                       ║",
            "╠═══════════════════════════════════════════════════════════════╣",
            "║  1. 📚 Atualizar Biblioteca Steam                            ║",
            "║  2. 💾 Instalar Biblioteca Steam                             ║", 
            "║  3. 🗑️  Remover Jogos Instalados                             ║",
            "║  4. 🔍 Mostrar Informações do Sistema                        ║",
            "║  5. 🚪 Sair                                                   ║",
            "╚═══════════════════════════════════════════════════════════════╝"
        ]
        
        print()
        for item in menu_items:
            self.print_gradient_text(item, 25)
        
        print()
        print(f"    {self.ESC}[38;2;100;255;100m👤 Usuário: {self.user_data.get('login', 'N/A')} (Cliente Ativo){self.ESC}[0m")
        
        # Mostrar informações detalhadas do MAC
        if self.current_mac:
            mac_masked = f"{self.current_mac[:8]}***{self.current_mac[-5:]}"
            print(f"    {self.ESC}[38;2;150;150;150m🖥️  Computador: {mac_masked}{self.ESC}[0m")
            if self.mac_detector.detection_method:
                print(f"    {self.ESC}[38;2;150;150;150m📡 Método Detecção: {self.mac_detector.detection_method}{self.ESC}[0m")
        else:
            print(f"    {self.ESC}[38;2;255;100;100m❌ Computador: Não identificado{self.ESC}[0m")
        
        # Mostrar status do arquivo
        if os.path.exists(self.config.LOCAL_ZIP):
            size_mb = os.path.getsize(self.config.LOCAL_ZIP) / 1024 / 1024
            print(f"    {self.ESC}[38;2;100;255;100m📦 Arquivo: COMPREJOGOS.zip ({size_mb:.1f} MB){self.ESC}[0m")
        else:
            print(f"    {self.ESC}[38;2;255;100;100m❌ Arquivo: COMPREJOGOS.zip não encontrado{self.ESC}[0m")
        
        print()
        
        choice = input(f"    {self.ESC}[38;2;255;20;147m➤ Escolha uma opção: {self.ESC}[0m")
        return choice.strip()
    
    def show_system_info(self):
        """Mostra informações detalhadas do sistema"""
        self.clear_screen()
        self.show_banner()
        print()
        
        self._show_user_message("Informações do Sistema", "info")
        print()
        
        print(f"    {self.ESC}[38;2;200;200;200m╔══════════════════════════════════════════════╗{self.ESC}[0m")
        print(f"    {self.ESC}[38;2;200;200;200m║              DETALHES TÉCNICOS               ║{self.ESC}[0m")
        print(f"    {self.ESC}[38;2;200;200;200m╚══════════════════════════════════════════════╝{self.ESC}[0m")
        print()
        
        # Informações básicas
        print(f"    📋 Versão: {self.config.VERSION}")
        print(f"    🖥️  Sistema: {platform.system()} {platform.release()}")
        print(f"    🐍 Python: {sys.version.split()[0]}")
        print()
        
        # Informações de MAC
        print(f"    🔗 MAC Address Completo: {self.current_mac or 'NÃO DETECTADO'}")
        print(f"    📡 Método de Detecção: {self.mac_detector.detection_method or 'N/A'}")
        print()
        
        # Informações de Steam
        if self.steam_dir:
            print(f"    🎮 Steam: {self.steam_dir}")
            print(f"    ⚙️  Config: {self.config_dir}")
        else:
            print(f"    ❌ Steam: Não encontrado")
        print()
        
        # Informações de autenticação
        if self.user_data.get('authenticated'):
            last_validation = self.user_data.get('last_validation', 0)
            if last_validation > 0:
                validation_time = time.strftime('%H:%M:%S', time.localtime(last_validation))
                print(f"    ✅ Última Validação: {validation_time}")
            
            auth_time = self.user_data.get('auth_timestamp', 0)
            if auth_time > 0:
                auth_time_str = time.strftime('%H:%M:%S', time.localtime(auth_time))
                print(f"    🔐 Login Realizado: {auth_time_str}")
        
        # Teste de conectividade
        print()
        self._show_user_message("Testando conectividade...", "loading")
        try:
            response = requests.get(self.config.get_base_url(), timeout=5)
            if response.status_code == 200:
                print(f"    ✅ Servidor: Online ({response.status_code})")
            else:
                print(f"    ⚠️  Servidor: Status {response.status_code}")
        except Exception as e:
            print(f"    ❌ Servidor: Offline ({str(e)[:50]}...)")
        
        print()
        input("    Pressione Enter para voltar ao menu...")
    
    def check_client_access(self, action_name):
        """Verifica acesso antes de ações críticas"""
        if not self.current_mac:
            self._show_user_message("Erro: Computador não identificado", "error")
            return False
        
        self._show_user_message("Verificando autorização...", "loading")
        
        if not self.validate_mac_consistency():
            self._show_user_message("Acesso negado. Computador não autorizado.", "error")
            self.user_data['authenticated'] = False
            self.user_data['is_client'] = False
            self.save_user_config()
            input("Pressione Enter para fazer login novamente...")
            return False
        
        if not self.validate_client_status():
            self._show_user_message("Acesso negado. Sua autorização foi revogada.", "error")
            self.user_data['authenticated'] = False
            self.user_data['is_client'] = False
            self.save_user_config()
            input("Pressione Enter para fazer login novamente...")
            return False
        
        return True
    
    def check_local_zip(self):
        """Verifica arquivo ZIP local"""
        return os.path.exists(self.config.LOCAL_ZIP)
    
    def extract_local_zip(self):
        """Extrai arquivo ZIP"""
        try:
            if not self.check_local_zip():
                return None
            
            temp_dir = os.path.join(tempfile.gettempdir(), f"cj_temp_{int(time.time())}")
            if os.path.exists(temp_dir):
                shutil.rmtree(temp_dir, ignore_errors=True)
            
            with zipfile.ZipFile(self.config.LOCAL_ZIP, 'r') as zip_ref:
                zip_ref.extractall(temp_dir)
            
            return temp_dir
        except Exception as e:
            self._log(f"Erro na extração: {e}")
            return None
    
    def close_steam(self):
        """Fecha Steam"""
        try:
            subprocess.run(["powershell", "-Command", "Get-Process steam -ErrorAction SilentlyContinue | Stop-Process -Force"],
                         check=False, capture_output=True, creationflags=subprocess.CREATE_NO_WINDOW)
        except Exception:
            pass
    
    def start_steam(self):
        """Inicia Steam"""
        try:
            subprocess.Popen([self.steam_exe], shell=True, stdout=subprocess.DEVNULL, 
                           stderr=subprocess.DEVNULL, creationflags=subprocess.CREATE_NO_WINDOW)
        except Exception:
            pass
    
    def remove_directory_force(self, path):
        """Remove diretório"""
        try:
            if os.path.exists(path):
                shutil.rmtree(path, ignore_errors=True)
        except Exception:
            pass
    
    def copy_files(self, temp_dir):
        """Copia arquivos"""
        try:
            config_source = os.path.join(temp_dir, "Config")
            if os.path.exists(config_source):
                shutil.copytree(config_source, self.config_dir, dirs_exist_ok=True)
            
            hid_dll_source = os.path.join(temp_dir, "Hid.dll")
            hid_dll_dest = os.path.join(self.steam_dir, "Hid.dll")
            if os.path.exists(hid_dll_source):
                shutil.copy2(hid_dll_source, hid_dll_dest)
        except Exception as e:
            self._log(f"Erro ao copiar arquivos: {e}")
    
    def atualizar_arquivos(self):
        """Atualizar biblioteca"""
        self.clear_screen()
        self.show_banner()
        print()
        
        if not self.check_client_access("atualização"):
            return
        
        if not self.check_local_zip():
            self._show_user_message("Arquivo COMPREJOGOS.zip não encontrado!", "error")
            input("Pressione Enter para continuar...")
            return
        
        self._show_user_message("Preparando atualização...", "loading")
        self.close_steam()
        
        self._show_user_message("Limpando arquivos antigos...", "loading")
        depot_cache = os.path.join(self.config_dir, "depotcache")
        stplug_in = os.path.join(self.config_dir, "stplug-in")
        
        self.remove_directory_force(depot_cache)
        self.remove_directory_force(stplug_in)
        
        self._show_user_message("Aplicando atualizações...", "loading")
        temp_dir = self.extract_local_zip()
        if temp_dir:
            self.copy_files(temp_dir)
            shutil.rmtree(temp_dir, ignore_errors=True)
            
            self._show_user_message("Reiniciando Steam...", "loading")
            self.start_steam()
            
            self._show_user_message("Atualização concluída com sucesso!", "success")
        else:
            self._show_user_message("Erro durante a atualização", "error")
        
        input("Pressione Enter para continuar...")
    
    def instalacao_simples(self):
        """Instalação completa"""
        self.clear_screen()
        self.show_banner()
        print()
        
        if not self.check_client_access("instalação"):
            return
        
        if not self.check_local_zip():
            self._show_user_message("Arquivo COMPREJOGOS.zip não encontrado!", "error")
            input("Pressione Enter para continuar...")
            return
        
        self._show_user_message("Iniciando instalação...", "loading")
        self.close_steam()
        
        temp_dir = self.extract_local_zip()
        if not temp_dir:
            self._show_user_message("Erro na preparação dos arquivos", "error")
            input("Pressione Enter para continuar...")
            return
        
        self._show_user_message("Removendo instalações anteriores...", "loading")
        self.remove_directory_force(os.path.join(self.config_dir, "depotcache"))
        self.remove_directory_force(os.path.join(self.config_dir, "stplug-in"))
        
        self._show_user_message("Instalando biblioteca...", "loading")
        self.copy_files(temp_dir)
        
        shutil.rmtree(temp_dir, ignore_errors=True)
        
        self._show_user_message("Finalizando instalação...", "loading")
        self.start_steam()
        
        self._show_user_message("Instalação concluída com sucesso!", "success")
        
        input("Pressione Enter para continuar...")
    
    def remover_jogos(self):
        """Remover jogos"""
        self.clear_screen()
        self.show_banner()
        print()
        
        if not self.check_client_access("remoção"):
            return
        
        self._show_user_message("Preparando remoção...", "loading")
        self.close_steam()
        
        self._show_user_message("Removendo arquivos...", "loading")
        hid_dll_path = os.path.join(self.steam_dir, "Hid.dll")
        try:
            if os.path.exists(hid_dll_path):
                os.remove(hid_dll_path)
        except Exception:
            pass
        
        depot_cache = os.path.join(self.config_dir, "depotcache")
        stplug_in = os.path.join(self.config_dir, "stplug-in")
        
        self.remove_directory_force(depot_cache)
        self.remove_directory_force(stplug_in)
        
        self._show_user_message("Remoção concluída com sucesso!", "success")
        
        input("Pressione Enter para continuar...")
    
    def run(self):
        """Executa o aplicativo"""
        # Verificar detecção de MAC
        if not self.current_mac:
            self.clear_screen()
            self.show_banner()
            print()
            self._show_user_message("ERRO CRÍTICO: Não foi possível identificar este computador", "error")
            print()
            self._show_user_message("Possíveis soluções:", "info")
            print("    • Execute o programa como Administrador")
            print("    • Verifique se há antivírus bloqueando")
            print("    • Certifique-se que está no Windows")
            print("    • Ative as interfaces de rede")
            print()
            
            if self.debug_mode:
                print(f"    📋 Informações de Debug:")
                for info in self.mac_detector.debug_info[-5:]:
                    print(f"       {info}")
                print()
            
            input("Pressione Enter para sair...")
            sys.exit(1)
        
        # Verificar autenticação
        if not self.check_authentication():
            while True:
                login, senha = self.show_login_screen()
                
                if not login or not senha:
                    self._show_user_message("Usuário e senha são obrigatórios", "error")
                    time.sleep(2)
                    continue
                
                if self.authenticate_user(login, senha):
                    break
        
        # Menu principal
        while True:
            if not self.check_authentication():
                self._show_user_message("Sua sessão expirou ou foi desativada", "warning")
                break
            
            self.show_banner()
            choice = self.show_menu()
            
            if choice == "1":
                self.atualizar_arquivos()
            elif choice == "2":
                self.instalacao_simples()
            elif choice == "3":
                self.remover_jogos()
            elif choice == "4":
                self.show_system_info()
            elif choice == "5":
                break
            else:
                self._show_user_message("Opção inválida", "error")
                time.sleep(1)

def main():
    """Função principal"""
    if os.name != 'nt':
        print("Este programa é compatível apenas com Windows.")
        sys.exit(1)
    
    try:
        installer = CompreJogosInstaller()
        installer.run()
    except KeyboardInterrupt:
        print("\n\nSaindo...")
    except Exception as e:
        if "--debug" in sys.argv:
            print(f"Erro: {e}")
            import traceback
            traceback.print_exc()
        else:
            print("Ocorreu um erro inesperado.")
        input("Pressione Enter para sair...")

if __name__ == "__main__":
    main()