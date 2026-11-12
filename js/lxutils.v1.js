/**
 * LXShimUtils v1.0 — 洛雪音源脚本扩展 API 浏览器实现
 * 为 SourceHub 的 globalThis.lx 模拟层补齐 lx.utils / lx.zlib：
 *   utils.buffer.from / bufToString / isBuffer
 *   utils.crypto.md5 / aesEncrypt / aesDecrypt / rsaEncrypt / randomBytes
 *   utils.randomBytes(n)
 *   zlib.inflate / deflate / gzip / gunzip / inflateRaw / deflateRaw
 * 除 zlib（浏览器 Streams API，支持 Promise 与回调两种用法）外全部同步实现，
 * 语义对齐 Node.js Buffer 与 node:crypto（字符串密钥按 UTF-8 取字节）。
 */
(function (global) {
  'use strict';

  /* ================= 字节基础工具 ================= */

  function utf8ToBytes(str) { return new TextEncoder().encode(String(str)); }
  function bytesToUtf8(u8) { return new TextDecoder('utf-8').decode(u8); }

  function bytesToHex(u8) {
    var s = '';
    for (var i = 0; i < u8.length; i++) s += (u8[i] >> 4).toString(16) + (u8[i] & 15).toString(16);
    return s;
  }

  function hexToBytes(hex) {
    hex = String(hex).replace(/[^0-9a-fA-F]/g, '');
    if (hex.length % 2) hex += '0';
    var u8 = new Uint8Array(hex.length / 2);
    for (var i = 0; i < u8.length; i++) u8[i] = parseInt(hex.substr(i * 2, 2), 16);
    return u8;
  }

  function bytesToB64(u8) {
    var s = '';
    for (var i = 0; i < u8.length; i += 0x8000) s += String.fromCharCode.apply(null, u8.subarray(i, i + 0x8000));
    return btoa(s);
  }

  function b64ToBytes(b64) {
    var s = atob(String(b64).replace(/\s+/g, ''));
    var u8 = new Uint8Array(s.length);
    for (var i = 0; i < s.length; i++) u8[i] = s.charCodeAt(i);
    return u8;
  }

  function bytesToLatin1(u8) {
    var s = '';
    for (var i = 0; i < u8.length; i += 0x8000) s += String.fromCharCode.apply(null, u8.subarray(i, i + 0x8000));
    return s;
  }

  function latin1ToBytes(s) {
    var u8 = new Uint8Array(s.length);
    for (var i = 0; i < s.length; i++) u8[i] = s.charCodeAt(i) & 0xff;
    return u8;
  }

  /* ================= Buffer（Node 语义子集） ================= */

  function LxBufferType(length) {
    var arr = new Uint8Array(length);
    arr.__proto__ = LxBufferType.prototype;
    return arr;
  }
  LxBufferType.prototype = Object.create(Uint8Array.prototype);
  LxBufferType.prototype.toString = function (encoding) {
    encoding = String(encoding == null ? 'utf-8' : encoding).toLowerCase().replace('_', '-');
    switch (encoding) {
      case 'utf8': case 'utf-8': return bytesToUtf8(this);
      case 'hex': return bytesToHex(this);
      case 'base64': return bytesToB64(this);
      case 'binary': case 'latin1': return bytesToLatin1(this);
      case 'ucs2': case 'ucs-2': case 'utf16le': case 'utf-16le': {
        var s = '';
        for (var i = 0; i + 1 < this.length; i += 2) s += String.fromCharCode(this[i] | (this[i + 1] << 8));
        return s;
      }
      default: return bytesToUtf8(this);
    }
  };
  LxBufferType.isBuffer = function (x) { return x instanceof LxBufferType; };

  function createBuffer(bytes) {
    var buf = new LxBufferType(bytes.length);
    buf.set(bytes);
    return buf;
  }

  function toBytesFromString(str, encoding) {
    encoding = String(encoding == null ? 'utf-8' : encoding).toLowerCase().replace('_', '-');
    if (encoding === 'hex') return hexToBytes(str);
    if (encoding === 'base64') return b64ToBytes(str);
    if (encoding === 'binary' || encoding === 'latin1') return latin1ToBytes(str);
    if (encoding === 'ucs2' || encoding === 'ucs-2' || encoding === 'utf16le' || encoding === 'utf-16le') {
      var u8 = new Uint8Array(str.length * 2);
      for (var i = 0; i < str.length; i++) {
        u8[i * 2] = str.charCodeAt(i) & 0xff;
        u8[i * 2 + 1] = (str.charCodeAt(i) >> 8) & 0xff;
      }
      return u8;
    }
    return utf8ToBytes(str);
  }

  function toBuffer(input, encoding) {
    if (input instanceof LxBufferType) return input;
    if (input instanceof Uint8Array) return createBuffer(input);
    if (input instanceof ArrayBuffer) return createBuffer(new Uint8Array(input));
    if (Array.isArray(input)) return createBuffer(input);
    if (typeof input === 'string') return createBuffer(toBytesFromString(input, encoding));
    if (typeof input === 'number') return new LxBufferType(input);
    return createBuffer(new Uint8Array(0));
  }

  var bufferApi = {
    from: toBuffer,
    isBuffer: function (x) { return x instanceof LxBufferType; },
    bufToString: function (buf, format) {
      if (!(buf instanceof Uint8Array)) buf = toBuffer(buf);
      return buf.toString(format);
    }
  };

  /* ================= MD5（RFC 1321） ================= */

  var MD5_K = new Int32Array(64), MD5_R = new Uint8Array(64);
  (function initMd5() {
    for (var k = 0; k < 64; k++) MD5_K[k] = Math.floor(Math.abs(Math.sin(k + 1)) * 4294967296);
    var shifts = [7, 12, 17, 22, 7, 12, 17, 22, 7, 12, 17, 22, 7, 12, 17, 22,
                  5, 9, 14, 20, 5, 9, 14, 20, 5, 9, 14, 20, 5, 9, 14, 20,
                  4, 11, 16, 23, 4, 11, 16, 23, 4, 11, 16, 23, 4, 11, 16, 23,
                  6, 10, 15, 21, 6, 10, 15, 21, 6, 10, 15, 21, 6, 10, 15, 21];
    for (var t = 0; t < 64; t++) MD5_R[t] = shifts[t];
  })();

  function md5rotl(x, c) { return (x << c) | (x >>> (32 - c)); }

  function md5(input) {
    var msg = input instanceof Uint8Array ? new Uint8Array(input) : utf8ToBytes(String(input));
    var origLen = msg.length;
    var total = (((origLen + 8) >> 6) + 1) * 64;
    var buf = new Uint8Array(total);
    buf.set(msg);
    buf[origLen] = 0x80;
    var bitLen = origLen * 8;
    // 注意：JS 位移量按 32 取模（x>>>32 === x），长度高 32 位（i>=4）必须直接补 0
    for (var i = 0; i < 4; i++) buf[buf.length - 8 + i] = (bitLen >>> (8 * i)) & 0xff;

    var a0 = 0x67452301 | 0, b0 = 0xefcdab89 | 0, c0 = 0x98badcfe | 0, d0 = 0x10325476 | 0;
    for (var off = 0; off < buf.length; off += 64) {
      var M = new Int32Array(16), j;
      for (j = 0; j < 16; j++) {
        M[j] = buf[off + j * 4] | (buf[off + j * 4 + 1] << 8) | (buf[off + j * 4 + 2] << 16) | (buf[off + j * 4 + 3] << 24);
      }
      var A = a0, B = b0, C = c0, D = d0, F, g, k;
      for (k = 0; k < 64; k++) {
        if (k < 16) { F = (B & C) | (~B & D); g = k; }
        else if (k < 32) { F = (D & B) | (~D & C); g = (5 * k + 1) % 16; }
        else if (k < 48) { F = B ^ C ^ D; g = (3 * k + 5) % 16; }
        else { F = C ^ (B | ~D); g = (7 * k) % 16; }
        F = (F + A + MD5_K[k] + M[g]) | 0;
        A = D; D = C; C = B;
        B = (B + md5rotl(F, MD5_R[k])) | 0;
      }
      a0 = (a0 + A) | 0; b0 = (b0 + B) | 0; c0 = (c0 + C) | 0; d0 = (d0 + D) | 0;
    }
    var out = new Uint8Array(16), o = [a0, b0, c0, d0];
    for (var oi = 0; oi < 4; oi++) {
      out[oi * 4] = o[oi] & 0xff;
      out[oi * 4 + 1] = (o[oi] >>> 8) & 0xff;
      out[oi * 4 + 2] = (o[oi] >>> 16) & 0xff;
      out[oi * 4 + 3] = (o[oi] >>> 24) & 0xff;
    }
    return bytesToHex(out);
  }
  /* ================= AES（由 vendored aes-js 提供，MIT，见 js/vendor/README.md） ================= */

  function pkcs7Pad(bytes) {
    var pad = 16 - (bytes.length % 16);
    var out = new Uint8Array(bytes.length + pad);
    out.set(bytes);
    for (var i = bytes.length; i < out.length; i++) out[i] = pad;
    return out;
  }

  function pkcs7Unpad(bytes) {
    if (!bytes.length) return bytes;
    var pad = bytes[bytes.length - 1];
    if (pad < 1 || pad > 16) return bytes;
    for (var i = bytes.length - pad; i < bytes.length; i++) if (bytes[i] !== pad) return bytes;
    return bytes.subarray(0, bytes.length - pad);
  }

  function aesEncrypt(data, mode, key, iv) {
    var aesjs = global.aesjs;
    if (!aesjs) throw new Error('缺少 aes-js 依赖（js/vendor/aes-js.js 须先于 lxutils 加载）');
    var m = String(mode || '').toLowerCase();
    var keyBytes = new Uint8Array(toBuffer(key));
    if ([16, 24, 32].indexOf(keyBytes.length) === -1) throw new Error('AES 密钥长度非法（须 16/24/32 字节）');
    var dataBytes = data instanceof Uint8Array ? new Uint8Array(data) : utf8ToBytes(String(data));
    var padded = pkcs7Pad(dataBytes), out, off;
    if (m.indexOf('ecb') !== -1) {
      var ecb = new aesjs.ModeOfOperation.ecb(keyBytes);
      out = new Uint8Array(padded.length);
      for (off = 0; off < padded.length; off += 16) out.set(ecb.encrypt(padded.subarray(off, off + 16)), off);
    } else {
      var ivBytes = new Uint8Array(iv ? toBuffer(iv) : new Uint8Array(16));
      if (ivBytes.length !== 16) throw new Error('AES CBC 模式 IV 须为 16 字节');
      out = new aesjs.ModeOfOperation.cbc(keyBytes, ivBytes).encrypt(padded);
    }
    return createBuffer(out);
  }

  function aesDecrypt(data, mode, key, iv) {
    var aesjs = global.aesjs;
    if (!aesjs) throw new Error('缺少 aes-js 依赖（js/vendor/aes-js.js 须先于 lxutils 加载）');
    var m = String(mode || '').toLowerCase();
    if (!(data instanceof Uint8Array)) data = utf8ToBytes(String(data));
    if (data.length % 16) throw new Error('AES 解密数据长度须为 16 的倍数');
    var keyBytes = new Uint8Array(toBuffer(key));
    if ([16, 24, 32].indexOf(keyBytes.length) === -1) throw new Error('AES 密钥长度非法（须 16/24/32 字节）');
    var dataBytes = new Uint8Array(data), dec, off;
    if (m.indexOf('ecb') !== -1) {
      var ecb = new aesjs.ModeOfOperation.ecb(keyBytes);
      dec = new Uint8Array(dataBytes.length);
      for (off = 0; off < dataBytes.length; off += 16) dec.set(ecb.decrypt(dataBytes.subarray(off, off + 16)), off);
    } else {
      var ivBytes = new Uint8Array(iv ? toBuffer(iv) : new Uint8Array(16));
      if (ivBytes.length !== 16) throw new Error('AES CBC 模式 IV 须为 16 字节');
      dec = new aesjs.ModeOfOperation.cbc(keyBytes, ivBytes).decrypt(dataBytes);
    }
    return createBuffer(pkcs7Unpad(dec));
  }

  /* ================= RSA（PKCS#1 v1.5，BigInt） ================= */

  function bytesToBig(u8) {
    var hex = bytesToHex(u8).replace(/^(00)+/, '');
    return hex ? BigInt('0x' + hex) : 0n;
  }

  function bigToBytes(big, len) {
    var hex = big.toString(16);
    if (hex.length % 2) hex = '0' + hex;
    var bytes = hexToBytes(hex);
    if (bytes.length > len) bytes = bytes.subarray(bytes.length - len);
    if (bytes.length < len) {
      var out = new Uint8Array(len);
      out.set(bytes, len - bytes.length);
      return out;
    }
    return bytes;
  }

  function modPow(base, exp, mod) {
    var result = 1n;
    base %= mod;
    while (exp > 0n) {
      if (exp & 1n) result = (result * base) % mod;
      base = (base * base) % mod;
      exp >>= 1n;
    }
    return result;
  }

  function parseRsaPublicKey(pem) {
    var body = String(pem).replace(/-----[A-Z ]*-----/g, '').replace(/\s+/g, '');
    var der = b64ToBytes(body);
    function readTLV(buf, pos) {
      var tag = buf[pos], len = buf[pos + 1], hl = 2, n, i;
      if (len & 0x80) {
        n = len & 0x7f; len = 0;
        for (i = 0; i < n; i++) len = len * 256 + buf[pos + 2 + i];
        hl = 2 + n;
      }
      return { tag: tag, len: len, start: pos + hl, next: pos + hl + len };
    }
    var outer = readTLV(der, 0);
    if (outer.tag !== 0x30) throw new Error('RSA 公钥格式非法');
    var first = readTLV(der, outer.start), nT, eT;
    if (first.tag === 0x02) { // PKCS#1: SEQUENCE { INTEGER n, INTEGER e }
      nT = first; eT = readTLV(der, first.next);
      return {
        n: bytesToBig(der.subarray(nT.start, nT.start + nT.len)),
        e: bytesToBig(der.subarray(eT.start, eT.start + eT.len))
      };
    }
    if (first.tag === 0x30) { // SPKI: SEQUENCE { SEQ{算法}, BITSTRING { PKCS#1 序列 } }
      var bits = readTLV(der, first.next);
      var content = der.subarray(bits.start + 1, bits.start + bits.len); // 首字节为 unused-bits
      var rsaSeq = readTLV(content, 0);
      nT = readTLV(content, rsaSeq.start);
      eT = readTLV(content, nT.next);
      return {
        n: bytesToBig(content.subarray(nT.start, nT.start + nT.len)),
        e: bytesToBig(content.subarray(eT.start, eT.start + eT.len))
      };
    }
    throw new Error('RSA 公钥结构无法识别');
  }

  function rsaEncrypt(data, key) {
    var pub = parseRsaPublicKey(key);
    var klen = Math.ceil(pub.n.toString(2).length / 8);
    var msg = data instanceof Uint8Array ? new Uint8Array(data) : utf8ToBytes(String(data));
    var chunks = [], off;
    for (off = 0; off < msg.length; off += klen - 11) {
      var chunk = msg.subarray(off, Math.min(off + klen - 11, msg.length));
      var block = new Uint8Array(klen), i, rb;
      block[0] = 0x00; block[1] = 0x02;
      for (i = 2; i < klen - chunk.length - 1; i++) {
        do { rb = Math.floor(Math.random() * 256); } while (rb === 0);
        block[i] = rb;
      }
      block.set(chunk, klen - chunk.length);
      chunks.push(bigToBytes(modPow(bytesToBig(block), pub.e, pub.n), klen));
    }
    var total = new Uint8Array(chunks.length * klen);
    chunks.forEach(function (c, idx) { total.set(c, idx * klen); });
    return createBuffer(total);
  }

  /* ================= randomBytes ================= */

  function randomBytes(n) {
    var u8 = new Uint8Array(n);
    if (global.crypto && global.crypto.getRandomValues) global.crypto.getRandomValues(u8);
    else for (var i = 0; i < n; i++) u8[i] = Math.floor(Math.random() * 256);
    return createBuffer(u8);
  }

  /* ================= zlib（Streams API，Promise 与回调双用法） ================= */

  var Z_MAP = {
    inflate: [DecompressionStream, 'deflate'],
    gunzip: [DecompressionStream, 'gzip'],
    inflateRaw: [DecompressionStream, 'deflate-raw'],
    deflate: [CompressionStream, 'deflate'],
    gzip: [CompressionStream, 'gzip'],
    deflateRaw: [CompressionStream, 'deflate-raw']
  };

  function zRun(data, kind) {
    var m = Z_MAP[kind];
    var u8 = data instanceof Uint8Array ? data : toBytesFromString(String(data));
    var stream = new Blob([u8]).stream().pipeThrough(new m[0](m[1]));
    return new Response(stream).arrayBuffer().then(function (ab) {
      return createBuffer(new Uint8Array(ab));
    });
  }

  function makeZ(kind) {
    return function (data, cb) {
      var p = zRun(data, kind);
      if (typeof cb === 'function') {
        p.then(function (buf) { cb(null, buf); }, function (err) { cb(err); });
        return;
      }
      return p;
    };
  }

  /* ================= 对外导出 ================= */

  var cryptoApi = {
    md5: md5,
    aesEncrypt: aesEncrypt,
    aesDecrypt: aesDecrypt,
    rsaEncrypt: rsaEncrypt,
    randomBytes: randomBytes
  };

  global.LxShimUtils = {
    buffer: bufferApi,
    crypto: cryptoApi,
    randomBytes: randomBytes,
    // 内部工具：SourceHub shim 处理 Buffer 请求体 / base64 响应时复用
    __bytesToLatin1: bytesToLatin1,
    __b64ToBytes: b64ToBytes,
    __bytesToB64: bytesToB64,
    __toBuffer: toBuffer,
  };

  global.LxShimZlib = {
    inflate: makeZ('inflate'),
    gunzip: makeZ('gunzip'),
    inflateRaw: makeZ('inflateRaw'),
    deflate: makeZ('deflate'),
    gzip: makeZ('gzip'),
    deflateRaw: makeZ('deflateRaw')
  };
})(window);
