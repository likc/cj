# ============================================================================
# config_embedded.py - Configurações para versão com arquivos embutidos
# ============================================================================

class CompreJogosConfig:
    """Configurações do sistema - Versão Embutida"""
    
    # Configurações do servidor - APENAS PARA LOGIN
    BASE_URL = "https://likc.net/comprejogos/"  # Mude para "https://comprejogos.com/" futuramente
    LOGIN_ENDPOINT = "api/login_simple.php"    # API simplificada apenas para login
    
    # Configurações locais
    CONFIG_FILE = "comprejogos_config.json"
    
    # Arquivo embutido no executável
    EMBEDDED_ZIP = "COMPREJOGOS.zip"
    
    # Informações do sistema
    VERSION = "2.0.0"
    APP_NAME = "COMPREJOGOS"
    
    # Configurações de autenticação
    SESSION_DURATION = 24  # horas
    MAX_RETRY_ATTEMPTS = 3
    
    @staticmethod
    def get_user_agent():
        """Retorna user agent personalizado"""
        return f"{CompreJogosConfig.APP_NAME}/{CompreJogosConfig.VERSION}"
    
    @staticmethod
    def get_headers():
        """Retorna headers padrão para requisições"""
        return {
            'User-Agent': CompreJogosConfig.get_user_agent(),
            'Accept': 'application/json, text/plain, */*',
            'Content-Type': 'application/x-www-form-urlencoded'
        }
    
    @staticmethod
    def validate_config():
        """Valida configurações básicas"""
        required_configs = [
            'BASE_URL',
            'LOGIN_ENDPOINT',
            'EMBEDDED_ZIP'
        ]
        
        for config in required_configs:
            if not hasattr(CompreJogosConfig, config):
                raise ValueError(f"Configuração obrigatória não encontrada: {config}")
        
        return True