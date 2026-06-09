// ============================================================
// arena-api.js — ponte entre as telas (navegador) e a API PHP
// Todas as telas incluem este arquivo e usam ArenaAPI.xxx()
// ============================================================
const ArenaAPI = (() => {
  // a API fica em /api relativo ao site
  const base = (location.origin + location.pathname).replace(/\/[^\/]*$/, '') + '/api';

  async function get(arquivo, params = {}) {
    const qs = new URLSearchParams(params).toString();
    const r = await fetch(`${base}/${arquivo}${qs ? '?' + qs : ''}`);
    return r.json();
  }
  async function post(arquivo, params, corpo = {}) {
    const qs = new URLSearchParams(params).toString();
    const r = await fetch(`${base}/${arquivo}${qs ? '?' + qs : ''}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(corpo)
    });
    return r.json();
  }

  return {
    // equipes
    listarEquipes: () => get('equipes.php', { acao: 'listar' }),
    criarEquipe: (e) => post('equipes.php', { acao: 'criar' }, e),
    addCorretor: (equipe_id, nome) => post('equipes.php', { acao: 'add_corretor' }, { equipe_id, nome }),
    removerEquipe: (equipe_id) => post('equipes.php', { acao: 'remover' }, { equipe_id }),
    resetEquipe: (equipe_id) => post('equipes.php', { acao: 'reset' }, { equipe_id }),
    setOnline: (equipe_id, online) => post('equipes.php', { acao: 'online' }, { equipe_id, online }),
    tirarDaSala: (equipe_id) => post('equipes.php', { acao: 'tirar_da_sala' }, { equipe_id }),
    alocarPresenca: (equipe_id, mesa_id, dia) => post('mesas.php', { acao: 'alocar_presenca' }, { equipe_id, mesa_id, dia }),
    importarCSV: (csv) => post('importar_csv.php', {}, { csv }),

    // pontos
    marcarPonto: (p) => post('pontos.php', { acao: 'marcar' }, p),
    pendentes: () => get('pontos.php', { acao: 'pendentes' }),
    aprovar: (ponto_id) => post('pontos.php', { acao: 'aprovar' }, { ponto_id }),
    rejeitar: (ponto_id, motivo) => post('pontos.php', { acao: 'rejeitar' }, { ponto_id, motivo }),

    // placar e config
    placar: () => get('placar.php'),
    lerConfig: () => get('config.php', { acao: 'ler' }),
    salvarConfig: (c) => post('config.php', { acao: 'salvar' }, c),
    rodada: (status) => post('config.php', { acao: 'rodada' }, { status }),
    startSala: (modo, valor) => post('config.php', { acao: 'start' }, { modo, valor }),

    // reservas
    listarReservas: () => get('reservas.php', { acao: 'listar' }),
    criarReserva: (r) => post('reservas.php', { acao: 'criar' }, r),
    removerReserva: (id) => post('reservas.php', { acao: 'remover' }, { id }),
    reposicionarReserva: (reserva_id, mesa_id) => post('reservas.php', { acao: 'reposicionar' }, { reserva_id, mesa_id }),
    liberarReserva: (reserva_id) => post('reservas.php', { acao: 'liberar' }, { reserva_id }),

    // mesas
    listarMesas: () => get('mesas.php', { acao: 'listar' }),
    renomearMesa: (mesa_id, nome) => post('mesas.php', { acao: 'renomear' }, { mesa_id, nome }),
    mapaMesas: (dia) => get('mesas.php', dia ? { acao: 'mapa', dia } : { acao: 'mapa' }),

    // usuários (login do agendamento)
    login: (login, senha) => post('usuarios.php', { acao: 'login' }, { login, senha }),
    listarUsuarios: () => get('usuarios.php', { acao: 'listar' }),
    criarUsuario: (u) => post('usuarios.php', { acao: 'criar' }, u),
    removerUsuario: (id) => post('usuarios.php', { acao: 'remover' }, { id }),
    resetarUsuario: (id, senha) => post('usuarios.php', { acao: 'resetar' }, { id, senha }),

    // duelos
    criarDuelo: (d) => post('duelos.php', { acao: 'criar' }, d),
    responderDuelo: (duelo_id, aceita) => post('duelos.php', { acao: 'responder' }, { duelo_id, aceita }),
    desafioParaMim: (equipe_id) => get('duelos.php', { acao: 'para_mim', equipe_id }),
    meuDuelo: (equipe_id) => get('duelos.php', { acao: 'meu_duelo', equipe_id }),
    desistirDuelo: (duelo_id, equipe_id) => post('duelos.php', { acao: 'desistir' }, { duelo_id, equipe_id }),
    entrarDuelo: (duelo_id, equipe_id) => post('duelos.php', { acao: 'entrar' }, { duelo_id, equipe_id }),
    duelosAtivos: () => get('duelos.php', { acao: 'ativos' }),
    encerrarDuelo: (duelo_id, vencedor_equipe_id) => post('duelos.php', { acao: 'encerrar' }, { duelo_id, vencedor_equipe_id }),

    // eventos (a TV puxa para animar)
    eventosNovos: (desde) => get('eventos.php', { acao: 'novos', desde: desde || 0 }),

    // contestações
    pontosDuelo: (duelo_id, equipe_id) => get('contestacoes.php', { acao: 'pontos_duelo', duelo_id, equipe_id }),
    contestar: (ponto_id, equipe_id) => post('contestacoes.php', { acao: 'contestar' }, { ponto_id, equipe_id }),
    contestacoesRecebidas: (duelo_id, equipe_id) => get('contestacoes.php', { acao: 'recebidas', duelo_id, equipe_id }),
    anularContestacao: (contestacao_id) => post('contestacoes.php', { acao: 'anular' }, { contestacao_id }),
    removerPonto: (contestacao_id) => post('contestacoes.php', { acao: 'remover' }, { contestacao_id }),

    // presença (check-in)
    presencaHoje: (equipe_id) => get('presencas.php', { acao: 'hoje', equipe_id }),
    presencaUltimo: (equipe_id) => get('presencas.php', { acao: 'ultimo', equipe_id }),
    marcarPresenca: (corretor_id, equipe_id) => post('presencas.php', { acao: 'marcar' }, { corretor_id, equipe_id }),
    desmarcarPresenca: (corretor_id) => post('presencas.php', { acao: 'desmarcar' }, { corretor_id }),
  };
})();
