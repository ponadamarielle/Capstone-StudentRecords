(function (root) {
    const ECC_PER_BLOCK = [0, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26];
    const BLOCKS = [0, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5];

    function mul(x, y) {
        let z = 0;
        for (let i = 7; i >= 0; i--) {
            z = (z << 1) ^ ((z >>> 7) * 0x11D);
            z ^= ((y >>> i) & 1) * x;
        }
        return z & 0xFF;
    }

    function rsDivisor(degree) {
        const result = [];
        for (let i = 0; i < degree - 1; i++) result.push(0);
        result.push(1);
        let rootValue = 1;
        for (let i = 0; i < degree; i++) {
            for (let j = 0; j < result.length; j++) {
                result[j] = mul(result[j], rootValue);
                if (j + 1 < result.length) result[j] ^= result[j + 1];
            }
            rootValue = mul(rootValue, 0x02);
        }
        return result;
    }

    function rsRemainder(data, divisor) {
        const result = divisor.map(() => 0);
        data.forEach((b) => {
            const factor = b ^ result.shift();
            result.push(0);
            divisor.forEach((coef, i) => { result[i] ^= mul(coef, factor); });
        });
        return result;
    }

    function rawModules(ver) {
        let result = (16 * ver + 128) * ver + 64;
        if (ver >= 2) {
            const numAlign = Math.floor(ver / 7) + 2;
            result -= (25 * numAlign - 10) * numAlign - 55;
            if (ver >= 7) result -= 36;
        }
        return result;
    }

    function dataCodewords(ver) {
        return Math.floor(rawModules(ver) / 8) - ECC_PER_BLOCK[ver] * BLOCKS[ver];
    }

    function alignPositions(ver, size) {
        if (ver === 1) return [];
        const numAlign = Math.floor(ver / 7) + 2;
        const step = Math.ceil((ver * 4 + 4) / (numAlign * 2 - 2)) * 2;
        const result = [6];
        for (let pos = size - 7; result.length < numAlign; pos -= step) result.splice(1, 0, pos);
        return result;
    }

    function bit(value, i) {
        return ((value >>> i) & 1) !== 0;
    }

    function encode(text) {
        const bytes = Array.from(new TextEncoder().encode(String(text)));
        let ver = 1;
        for (; ver <= 10; ver++) {
            const countBits = ver <= 9 ? 8 : 16;
            if (4 + countBits + bytes.length * 8 <= dataCodewords(ver) * 8) break;
        }
        if (ver > 10) throw new Error('Text is too long for the QR code.');

        const bits = [];
        const push = (value, length) => { for (let i = length - 1; i >= 0; i--) bits.push((value >>> i) & 1); };
        push(4, 4);
        push(bytes.length, ver <= 9 ? 8 : 16);
        bytes.forEach((b) => push(b, 8));
        const capacity = dataCodewords(ver) * 8;
        push(0, Math.min(4, capacity - bits.length));
        push(0, (8 - bits.length % 8) % 8);
        for (let pad = 0xEC; bits.length < capacity; pad ^= 0xEC ^ 0x11) push(pad, 8);
        const data = [];
        for (let i = 0; i < bits.length; i += 8) {
            let value = 0;
            for (let j = 0; j < 8; j++) value = (value << 1) | bits[i + j];
            data.push(value);
        }

        const numBlocks = BLOCKS[ver];
        const eccLen = ECC_PER_BLOCK[ver];
        const rawCodewords = Math.floor(rawModules(ver) / 8);
        const numShort = numBlocks - rawCodewords % numBlocks;
        const shortLen = Math.floor(rawCodewords / numBlocks);
        const divisor = rsDivisor(eccLen);
        const blocks = [];
        for (let i = 0, k = 0; i < numBlocks; i++) {
            const dat = data.slice(k, k + shortLen - eccLen + (i < numShort ? 0 : 1));
            k += dat.length;
            const ecc = rsRemainder(dat, divisor);
            if (i < numShort) dat.push(0);
            blocks.push(dat.concat(ecc));
        }
        const codewords = [];
        for (let i = 0; i < blocks[0].length; i++) {
            blocks.forEach((block, j) => {
                if (i !== shortLen - eccLen || j >= numShort) codewords.push(block[i]);
            });
        }

        const size = ver * 4 + 17;
        const modules = [];
        const isFunction = [];
        for (let y = 0; y < size; y++) {
            modules.push(new Array(size).fill(false));
            isFunction.push(new Array(size).fill(false));
        }
        const setFn = (x, y, dark) => { modules[y][x] = dark; isFunction[y][x] = true; };

        for (let i = 0; i < size; i++) {
            setFn(6, i, i % 2 === 0);
            setFn(i, 6, i % 2 === 0);
        }
        [[3, 3], [size - 4, 3], [3, size - 4]].forEach(([cx, cy]) => {
            for (let dy = -4; dy <= 4; dy++) {
                for (let dx = -4; dx <= 4; dx++) {
                    const dist = Math.max(Math.abs(dx), Math.abs(dy));
                    const xx = cx + dx;
                    const yy = cy + dy;
                    if (xx >= 0 && xx < size && yy >= 0 && yy < size) setFn(xx, yy, dist !== 2 && dist !== 4);
                }
            }
        });
        const align = alignPositions(ver, size);
        const last = align.length - 1;
        align.forEach((ay, i) => {
            align.forEach((ax, j) => {
                if ((i === 0 && j === 0) || (i === 0 && j === last) || (i === last && j === 0)) return;
                for (let dy = -2; dy <= 2; dy++) {
                    for (let dx = -2; dx <= 2; dx++) setFn(ax + dx, ay + dy, Math.max(Math.abs(dx), Math.abs(dy)) !== 1);
                }
            });
        });

        const drawFormat = (mask) => {
            const dataBits = mask;
            let rem = dataBits;
            for (let i = 0; i < 10; i++) rem = (rem << 1) ^ ((rem >>> 9) * 0x537);
            const value = ((dataBits << 10) | rem) ^ 0x5412;
            for (let i = 0; i <= 5; i++) setFn(8, i, bit(value, i));
            setFn(8, 7, bit(value, 6));
            setFn(8, 8, bit(value, 7));
            setFn(7, 8, bit(value, 8));
            for (let i = 9; i < 15; i++) setFn(14 - i, 8, bit(value, i));
            for (let i = 0; i < 8; i++) setFn(size - 1 - i, 8, bit(value, i));
            for (let i = 8; i < 15; i++) setFn(8, size - 15 + i, bit(value, i));
            setFn(8, size - 8, true);
        };
        drawFormat(0);
        if (ver >= 7) {
            let rem = ver;
            for (let i = 0; i < 12; i++) rem = (rem << 1) ^ ((rem >>> 11) * 0x1F25);
            const value = (ver << 12) | rem;
            for (let i = 0; i < 18; i++) {
                const a = size - 11 + i % 3;
                const b = Math.floor(i / 3);
                setFn(a, b, bit(value, i));
                setFn(b, a, bit(value, i));
            }
        }

        let index = 0;
        for (let right = size - 1; right >= 1; right -= 2) {
            if (right === 6) right = 5;
            for (let vert = 0; vert < size; vert++) {
                for (let j = 0; j < 2; j++) {
                    const x = right - j;
                    const upward = ((right + 1) & 2) === 0;
                    const y = upward ? size - 1 - vert : vert;
                    if (!isFunction[y][x] && index < codewords.length * 8) {
                        modules[y][x] = bit(codewords[index >>> 3], 7 - (index & 7));
                        index++;
                    }
                }
            }
        }

        const maskTest = [
            (x, y) => (x + y) % 2 === 0,
            (x, y) => y % 2 === 0,
            (x) => x % 3 === 0,
            (x, y) => (x + y) % 3 === 0,
            (x, y) => (Math.floor(x / 3) + Math.floor(y / 2)) % 2 === 0,
            (x, y) => x * y % 2 + x * y % 3 === 0,
            (x, y) => (x * y % 2 + x * y % 3) % 2 === 0,
            (x, y) => ((x + y) % 2 + x * y % 3) % 2 === 0,
        ];
        const applyMask = (mask) => {
            for (let y = 0; y < size; y++) {
                for (let x = 0; x < size; x++) {
                    if (!isFunction[y][x] && maskTest[mask](x, y)) modules[y][x] = !modules[y][x];
                }
            }
        };
        const penalty = () => {
            let score = 0;
            let dark = 0;
            for (let y = 0; y < size; y++) {
                let runRow = 1;
                let runCol = 1;
                for (let x = 0; x < size; x++) {
                    if (modules[y][x]) dark++;
                    if (x > 0) {
                        if (modules[y][x] === modules[y][x - 1]) {
                            runRow++;
                            if (runRow === 5) score += 3; else if (runRow > 5) score++;
                        } else {
                            runRow = 1;
                        }
                        if (modules[x][y] === modules[x - 1][y]) {
                            runCol++;
                            if (runCol === 5) score += 3; else if (runCol > 5) score++;
                        } else {
                            runCol = 1;
                        }
                    }
                    if (x > 0 && y > 0) {
                        const c = modules[y][x];
                        if (c === modules[y][x - 1] && c === modules[y - 1][x] && c === modules[y - 1][x - 1]) score += 3;
                    }
                }
            }
            const total = size * size;
            score += (Math.ceil(Math.abs(dark * 20 - total * 10) / total) - 1) * 10;
            return score;
        };

        let best = 0;
        let bestScore = Infinity;
        for (let mask = 0; mask < 8; mask++) {
            applyMask(mask);
            drawFormat(mask);
            const score = penalty();
            if (score < bestScore) {
                best = mask;
                bestScore = score;
            }
            applyMask(mask);
        }
        applyMask(best);
        drawFormat(best);
        return modules;
    }

    function svg(text, scale) {
        const modules = encode(text);
        const border = 4;
        const size = modules.length + border * 2;
        let path = '';
        modules.forEach((row, y) => row.forEach((dark, x) => {
            if (dark) path += 'M' + (x + border) + ',' + (y + border) + 'h1v1h-1z';
        }));
        const px = size * (scale || 6);
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + size + ' ' + size + '" width="' + px + '" height="' + px
            + '" shape-rendering="crispEdges" role="img" aria-label="QR code"><rect width="100%" height="100%" fill="#ffffff"/><path d="'
            + path + '" fill="#000000"/></svg>';
    }

    root.PupQr = { encode: encode, svg: svg };
})(typeof window !== 'undefined' ? window : globalThis);
