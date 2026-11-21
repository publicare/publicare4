import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
export const RAIZ = join(dirname(fileURLToPath(import.meta.url)), '..', '..', 'prototipo-ux');
