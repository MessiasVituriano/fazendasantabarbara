/** Turno padrão da ordenha pelo relógio: antes do meio-dia é Manhã, depois é Tarde. */
export const turnoAtual = () => (new Date().getHours() < 12 ? 'MANHA' : 'TARDE')
