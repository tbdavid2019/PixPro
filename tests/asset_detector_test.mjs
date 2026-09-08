import { readFileSync, existsSync, writeFileSync, unlinkSync } from 'node:fs';
import { resolve } from 'node:path';
import { execSync } from 'node:child_process';
import process from 'node:process';

const projectRoot = resolve(import.meta.dirname, '..');

function assert(condition, message) {
    if (!condition) {
        console.error('❌ ' + message);
        process.exit(1);
    }
}

console.log('🧪 Running AssetDetector & Magika regression tests...');

// 1. Check config/detector.php existence and methods
const detectorPath = resolve(projectRoot, 'config/detector.php');
assert(existsSync(detectorPath), 'config/detector.php must exist.');

const detectorCode = readFileSync(detectorPath, 'utf8');
assert(detectorCode.includes('class AssetDetector'), 'AssetDetector class must be defined.');
assert(detectorCode.includes('function detect('), 'AssetDetector::detect must be implemented.');
assert(detectorCode.includes('function isDangerous('), 'AssetDetector::isDangerous must be implemented.');
assert(detectorCode.includes('function routeAsset('), 'AssetDetector::routeAsset must be implemented.');
assert(detectorCode.includes('function isMagikaAvailable('), 'AssetDetector::isMagikaAvailable must be implemented.');

// Check dangerous labels blacklist
const requiredDangerousLabels = ['php', 'shell', 'bash', 'elf', 'pebin', 'wasm', 'python'];
for (const label of requiredDangerousLabels) {
    assert(detectorCode.includes(`'${label}'`), `AssetDetector must include '${label}' in dangerous labels.`);
}

// 2. Check integration across entrypoints
const uploadPhp = readFileSync(resolve(projectRoot, 'config/upload.php'), 'utf8');
assert(uploadPhp.includes("require_once __DIR__ . '/detector.php';"), 'config/upload.php must require detector.php.');
assert(uploadPhp.includes('AssetDetector::detect'), 'config/upload.php must call AssetDetector::detect.');
assert(uploadPhp.includes('AssetDetector::isDangerous'), 'config/upload.php must block dangerous files.');

const apiPhp = readFileSync(resolve(projectRoot, 'api.php'), 'utf8');
assert(apiPhp.includes('AssetDetector::detect'), 'api.php must call AssetDetector::detect.');
assert(apiPhp.includes('AssetDetector::isDangerous'), 'api.php must check AssetDetector::isDangerous.');
assert(apiPhp.includes('AssetDetector::routeAsset'), 'api.php must route uploads using AssetDetector::routeAsset.');

const apiFilePhp = readFileSync(resolve(projectRoot, 'api_file.php'), 'utf8');
assert(apiFilePhp.includes('AssetDetector::detect'), 'api_file.php must inspect files with AssetDetector::detect.');
assert(apiFilePhp.includes('AssetDetector::isDangerous'), 'api_file.php must check AssetDetector::isDangerous.');

const videoLogic = readFileSync(resolve(projectRoot, 'config/video_logic.php'), 'utf8');
assert(videoLogic.includes('AssetDetector::isDangerous'), 'config/video_logic.php must check AssetDetector::isDangerous.');

const audioLogic = readFileSync(resolve(projectRoot, 'config/audio_logic.php'), 'utf8');
assert(audioLogic.includes('AssetDetector::isDangerous'), 'config/audio_logic.php must check AssetDetector::isDangerous.');

const dockerfile = readFileSync(resolve(projectRoot, 'Dockerfile'), 'utf8');
assert(dockerfile.includes('magika-installer.sh'), 'Dockerfile must install Magika via official installer.');
assert(dockerfile.includes('magika --version'), 'Dockerfile must verify magika installation.');

// 3. Live Magika verification (if installed on runner)
let hasMagika = false;
try {
    const whichOut = execSync('which magika 2>/dev/null || true', { encoding: 'utf8' }).trim();
    if (whichOut) {
        hasMagika = true;
    }
} catch {
    hasMagika = false;
}

if (hasMagika) {
    console.log('⚡ Detected local Magika CLI, running live model inference tests...');

    // Test A: Real image detection
    const samplePng = resolve(projectRoot, 'static/favicon-32x32.png');
    if (existsSync(samplePng)) {
        const out = execSync(`magika --json "${samplePng}"`, { encoding: 'utf8' });
        const json = JSON.parse(out);
        const res = json[0]?.result?.value?.output || json[0]?.result?.value?.dl;
        assert(res?.label === 'png', `Expected PNG label for favicon, got: ${res?.label}`);
        assert(res?.group === 'image', `Expected image group for favicon, got: ${res?.group}`);
        console.log('  ✔ Genuine PNG detected as group:image label:png');
    }

    // Test B: Disguised PHP script with .jpg extension (WebShell simulation)
    const fakeJpgPath = resolve(projectRoot, 'storage/test_malicious_fake.jpg');
    try {
        writeFileSync(fakeJpgPath, '<?php phpinfo(); system($_GET["cmd"]); ?>\n');
        const out = execSync(`magika --json "${fakeJpgPath}"`, { encoding: 'utf8' });
        const json = JSON.parse(out);
        const res = json[0]?.result?.value?.output || json[0]?.result?.value?.dl;
        assert(res?.label === 'php', `Expected disguised file to be identified as 'php', got: ${res?.label}`);
        assert(res?.group === 'code', `Expected disguised file group to be 'code', got: ${res?.group}`);
        console.log('  ✔ Disguised PHP web shell successfully flagged as label:php (not fooled by .jpg)');
    } finally {
        if (existsSync(fakeJpgPath)) {
            unlinkSync(fakeJpgPath);
        }
    }

    // Test C: Empty file
    const emptyPath = resolve(projectRoot, 'storage/test_empty.jpg');
    try {
        writeFileSync(emptyPath, '');
        const out = execSync(`magika --json "${emptyPath}"`, { encoding: 'utf8' });
        const json = JSON.parse(out);
        const res = json[0]?.result?.value?.output || json[0]?.result?.value?.dl;
        assert(res?.label === 'empty', `Expected empty label, got: ${res?.label}`);
        console.log('  ✔ Empty file identified as label:empty');
    } finally {
        if (existsSync(emptyPath)) {
            unlinkSync(emptyPath);
        }
    }
} else {
    console.log('ℹ Magika CLI not found on this environment; verified fallback and code contracts.');
}

console.log('✅ AssetDetector & Magika regression test suite passed cleanly.');
