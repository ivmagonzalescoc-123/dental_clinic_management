// Interactive 3D tooth for the landing-page hero.
//
// Loaded lazily by index.php (dynamic import) only when the hero is on screen and
// WebGL is available. The model is a decimated copy of the odontogram's
// mandibular first molar packed as:
//   [u32 vertexCount][u32 indexCount][i16 xyz * vertexCount][pad to 4][u16 indices]
// with positions normalized so the longest side spans -1..1 (see
// scripts/build_hero_tooth.mjs). No textures: the look is all material + shaders.
import * as THREE from '../vendor/three/build/three.module.min.js';

const CYAN = 0x7fd3f2;
const SCAN_COLOR = 'vec3(0.36, 0.82, 1.0)';

async function loadToothGeometry(url) {
    const response = await fetch(url);
    if (!response.ok) throw new Error('Tooth model failed to load (' + response.status + ')');
    const buffer = await response.arrayBuffer();
    const view = new DataView(buffer);
    const vertexCount = view.getUint32(0, true);
    const indexCount = view.getUint32(4, true);

    const quantized = new Int16Array(buffer, 8, vertexCount * 3);
    const positions = new Float32Array(vertexCount * 3);
    for (let i = 0; i < positions.length; i++) positions[i] = quantized[i] / 32767;

    const positionBytes = 8 + vertexCount * 6;
    const indexOffset = positionBytes + ((4 - (positionBytes % 4)) % 4);
    const indices = new Uint16Array(buffer, indexOffset, indexCount);

    const geometry = new THREE.BufferGeometry();
    geometry.setAttribute('position', new THREE.BufferAttribute(positions, 3));
    geometry.setIndex(new THREE.BufferAttribute(indices, 1));
    // Source model's long axis is Z with the crown at +Z; stand it upright, crown on top.
    geometry.rotateX(-Math.PI / 2);
    geometry.computeVertexNormals();
    geometry.computeBoundingBox();
    return geometry;
}

// Soft round sprite reused by the glow, the platform and the particles.
function makeGlowTexture() {
    const size = 128;
    const canvas = document.createElement('canvas');
    canvas.width = canvas.height = size;
    const ctx = canvas.getContext('2d');
    const gradient = ctx.createRadialGradient(size / 2, size / 2, 0, size / 2, size / 2, size / 2);
    gradient.addColorStop(0, 'rgba(255,255,255,1)');
    gradient.addColorStop(0.35, 'rgba(255,255,255,0.35)');
    gradient.addColorStop(1, 'rgba(255,255,255,0)');
    ctx.fillStyle = gradient;
    ctx.fillRect(0, 0, size, size);
    const texture = new THREE.CanvasTexture(canvas);
    texture.colorSpace = THREE.SRGBColorSpace;
    return texture;
}

// Tiny procedural "studio" so the enamel has soft highlights to reflect,
// instead of shipping an HDR file.
function makeEnvironment(renderer) {
    const envScene = new THREE.Scene();
    // Mid-tone backdrop: a dark one shows up as a black fringe in grazing-angle reflections.
    envScene.background = new THREE.Color(0x5f8199);
    const plane = new THREE.PlaneGeometry(1, 1);
    const panels = [
        [0xffffff, 3.0, [0, 4, 2], [6, 2]],
        [0x9dd2e6, 2.2, [-4, 0.5, 1], [2, 5]],
        [0x1b7aa3, 2.0, [4, -0.5, -1], [2, 5]],
        [0xffffff, 1.2, [0, -3, 3], [5, 1]],
    ];
    panels.forEach(function([color, intensity, position, scale]) {
        const material = new THREE.MeshBasicMaterial({ color: new THREE.Color(color).multiplyScalar(intensity), side: THREE.DoubleSide });
        const mesh = new THREE.Mesh(plane, material);
        mesh.position.set(position[0], position[1], position[2]);
        mesh.scale.set(scale[0], scale[1], 1);
        mesh.lookAt(0, 0, 0);
        envScene.add(mesh);
    });
    const pmrem = new THREE.PMREMGenerator(renderer);
    const target = pmrem.fromScene(envScene, 0.04);
    pmrem.dispose();
    plane.dispose();
    envScene.traverse(function(obj) { if (obj.material) obj.material.dispose(); });
    return target;
}

export async function mountHeroTooth(container, options) {
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const pixelRatio = Math.min(window.devicePixelRatio || 1, 1.75);

    const canvas = document.createElement('canvas');
    canvas.className = 'hero-tooth-canvas';
    canvas.setAttribute('aria-hidden', 'true');

    const renderer = new THREE.WebGLRenderer({
        canvas: canvas,
        alpha: true,
        antialias: true,
        powerPreference: 'low-power'
    });
    renderer.setPixelRatio(pixelRatio);
    renderer.setClearColor(0x000000, 0);
    renderer.toneMapping = THREE.ACESFilmicToneMapping;
    renderer.toneMappingExposure = 1.05;

    const geometry = await loadToothGeometry(options.modelUrl);
    container.appendChild(canvas);

    const scene = new THREE.Scene();
    const envTarget = makeEnvironment(renderer);
    scene.environment = envTarget.texture;

    const camera = new THREE.PerspectiveCamera(30, 1, 0.1, 50);
    const baseCameraZ = 6.4;
    camera.position.set(0, 0.2, baseCameraZ);
    camera.lookAt(0, 0, 0);

    const keyLight = new THREE.DirectionalLight(0xffffff, 1.3);
    keyLight.position.set(2.5, 3, 4);
    const accentLight = new THREE.PointLight(0x5cc8f0, 6, 8, 2);
    accentLight.position.set(-2, -1.2, 1.5);
    scene.add(keyLight, accentLight);

    const glowTexture = makeGlowTexture();
    const shared = {
        uScanY: { value: -2 },
        uScanGlow: { value: 0 },
        uTime: { value: 0 }
    };

    // Everything floats together; `spin` holds the user-controlled rotation.
    const root = new THREE.Group();
    const spin = new THREE.Group();
    root.add(spin);
    scene.add(root);

    // --- Tooth: glossy pearl enamel with a holographic scan band ---
    const toothMaterial = new THREE.MeshPhysicalMaterial({
        color: 0xf5f9fc,
        roughness: 0.3,
        metalness: 0,
        clearcoat: 1,
        clearcoatRoughness: 0.08,
        sheen: 0.5,
        sheenColor: new THREE.Color(0x9dd2e6),
        sheenRoughness: 0.5,
        iridescence: 0.25,
        iridescenceIOR: 1.3,
        iridescenceThicknessRange: [250, 420],
        envMapIntensity: 1.1
    });
    toothMaterial.onBeforeCompile = function(shader) {
        shader.uniforms.uScanY = shared.uScanY;
        shader.uniforms.uScanGlow = shared.uScanGlow;
        shader.vertexShader = 'varying float vLocalY;\n' + shader.vertexShader.replace(
            '#include <begin_vertex>',
            '#include <begin_vertex>\n    vLocalY = position.y;'
        );
        shader.fragmentShader = 'uniform float uScanY;\nuniform float uScanGlow;\nvarying float vLocalY;\n' + shader.fragmentShader.replace(
            '#include <emissivemap_fragment>',
            '#include <emissivemap_fragment>\n' +
            '    float scanBand = 1.0 - smoothstep(0.0, 0.035, abs(vLocalY - uScanY));\n' +
            '    totalEmissiveRadiance += ' + SCAN_COLOR + ' * scanBand * 1.4 * uScanGlow;'
        );
    };
    const tooth = new THREE.Mesh(geometry, toothMaterial);
    spin.add(tooth);

    // Fresnel rim: a faint cyan edge that reads as "hologram" against the dark hero.
    const rimMaterial = new THREE.ShaderMaterial({
        uniforms: { uColor: { value: new THREE.Color(0x5cc8f0) }, uIntensity: { value: 0.5 } },
        vertexShader: [
            'varying vec3 vNormalV;',
            'varying vec3 vViewDir;',
            'void main() {',
            '    vec4 mv = modelViewMatrix * vec4(position, 1.0);',
            '    vNormalV = normalize(normalMatrix * normal);',
            '    vViewDir = normalize(-mv.xyz);',
            '    gl_Position = projectionMatrix * mv;',
            '}'
        ].join('\n'),
        fragmentShader: [
            'uniform vec3 uColor;',
            'uniform float uIntensity;',
            'varying vec3 vNormalV;',
            'varying vec3 vViewDir;',
            'void main() {',
            '    float f = pow(1.0 - abs(dot(normalize(vNormalV), normalize(vViewDir))), 2.5) * uIntensity;',
            '    gl_FragColor = vec4(uColor * f, f);',
            '}'
        ].join('\n'),
        transparent: true,
        blending: THREE.AdditiveBlending,
        depthWrite: false
    });
    const rim = new THREE.Mesh(geometry, rimMaterial);
    rim.scale.setScalar(1.015);
    spin.add(rim);

    // "Scanned" vertices: surface points that light up only as the scan band passes.
    const scanPointsMaterial = new THREE.ShaderMaterial({
        uniforms: {
            uScanY: shared.uScanY,
            uScanGlow: shared.uScanGlow,
            uSize: { value: 2.4 * pixelRatio }
        },
        vertexShader: [
            'uniform float uScanY;',
            'uniform float uSize;',
            'varying float vAlpha;',
            'void main() {',
            '    vAlpha = 1.0 - smoothstep(0.0, 0.12, abs(position.y - uScanY));',
            '    gl_PointSize = uSize * (0.6 + vAlpha);',
            '    gl_Position = projectionMatrix * modelViewMatrix * vec4(position * 1.02, 1.0);',
            '}'
        ].join('\n'),
        fragmentShader: [
            'uniform float uScanGlow;',
            'varying float vAlpha;',
            'void main() {',
            '    vec2 c = gl_PointCoord - 0.5;',
            '    float r = dot(c, c);',
            '    if (r > 0.25) discard;',
            '    float a = vAlpha * uScanGlow * (1.0 - r * 4.0);',
            '    gl_FragColor = vec4(vec3(0.55, 0.9, 1.0) * a, a);',
            '}'
        ].join('\n'),
        transparent: true,
        blending: THREE.AdditiveBlending,
        depthWrite: false
    });
    spin.add(new THREE.Points(geometry, scanPointsMaterial));

    // --- Orbit rings with a travelling light ---
    const rings = [];
    function addRing(radius, tiltX, tiltZ, opacity, speed) {
        const pivot = new THREE.Group();
        pivot.rotation.set(tiltX, 0, tiltZ);
        const ringMaterial = new THREE.MeshBasicMaterial({ color: CYAN, transparent: true, opacity: opacity, blending: THREE.AdditiveBlending, depthWrite: false });
        const ring = new THREE.Mesh(new THREE.TorusGeometry(radius, 0.0045, 6, 160), ringMaterial);
        const orbiter = new THREE.Group();
        const dot = new THREE.Sprite(new THREE.SpriteMaterial({ map: glowTexture, color: 0xbfeaff, transparent: true, blending: THREE.AdditiveBlending, depthWrite: false }));
        dot.scale.setScalar(0.16);
        dot.position.set(radius, 0, 0);
        orbiter.add(dot);
        pivot.add(ring, orbiter);
        root.add(pivot);
        rings.push({ orbiter: orbiter, speed: speed });
    }
    addRing(1.3, Math.PI / 2 - 0.28, 0.12, 0.35, 0.45);
    addRing(1.55, Math.PI / 2 + 0.42, -0.35, 0.18, -0.3);

    // --- Holographic platform ---
    const platformY = -1.3;
    const platformGlow = new THREE.Mesh(
        new THREE.PlaneGeometry(1, 1),
        new THREE.MeshBasicMaterial({ map: glowTexture, color: 0x3fb4e6, transparent: true, opacity: 0.55, blending: THREE.AdditiveBlending, depthWrite: false })
    );
    platformGlow.rotation.x = -Math.PI / 2;
    platformGlow.position.y = platformY;
    platformGlow.scale.setScalar(2.6);
    root.add(platformGlow);

    const platformRing = new THREE.Mesh(
        new THREE.RingGeometry(0.86, 0.875, 96),
        new THREE.MeshBasicMaterial({ color: CYAN, transparent: true, opacity: 0.45, blending: THREE.AdditiveBlending, depthWrite: false, side: THREE.DoubleSide })
    );
    platformRing.rotation.x = -Math.PI / 2;
    platformRing.position.y = platformY;
    root.add(platformRing);

    // Expanding ring fired on click/tap.
    const pulseRing = new THREE.Mesh(
        new THREE.RingGeometry(0.8, 0.83, 96),
        new THREE.MeshBasicMaterial({ color: 0xbfeaff, transparent: true, opacity: 0, blending: THREE.AdditiveBlending, depthWrite: false, side: THREE.DoubleSide })
    );
    pulseRing.rotation.x = -Math.PI / 2;
    pulseRing.position.y = platformY;
    root.add(pulseRing);

    // --- Drifting particles (animated entirely on the GPU) ---
    const particleCount = reduceMotion ? 40 : 90;
    const particleSeeds = new Float32Array(particleCount * 4);
    for (let i = 0; i < particleCount; i++) {
        const angle = Math.random() * Math.PI * 2;
        const radius = 0.8 + Math.random() * 1.2;
        particleSeeds[i * 4] = Math.cos(angle) * radius;
        particleSeeds[i * 4 + 1] = Math.random() * 3;
        particleSeeds[i * 4 + 2] = Math.sin(angle) * radius;
        particleSeeds[i * 4 + 3] = Math.random();
    }
    const particleGeometry = new THREE.BufferGeometry();
    particleGeometry.setAttribute('position', new THREE.BufferAttribute(new Float32Array(particleCount * 3), 3));
    particleGeometry.setAttribute('seed', new THREE.BufferAttribute(particleSeeds, 4));
    const particleMaterial = new THREE.ShaderMaterial({
        uniforms: { uTime: shared.uTime, uSize: { value: 3.2 * pixelRatio }, uSpeed: { value: reduceMotion ? 0 : 0.12 } },
        vertexShader: [
            'attribute vec4 seed;',
            'uniform float uTime;',
            'uniform float uSize;',
            'uniform float uSpeed;',
            'varying float vAlpha;',
            'void main() {',
            '    float y = mod(seed.y + uTime * uSpeed * (0.6 + seed.w), 3.0);',
            '    vec3 p = vec3(seed.x, y - 1.4, seed.z);',
            '    vAlpha = smoothstep(0.0, 0.4, y) * smoothstep(3.0, 2.4, y) * (0.35 + 0.35 * sin(uTime * 2.0 + seed.w * 40.0));',
            '    vec4 mv = modelViewMatrix * vec4(p, 1.0);',
            '    gl_PointSize = uSize * (0.5 + seed.w) * (4.0 / -mv.z);',
            '    gl_Position = projectionMatrix * mv;',
            '}'
        ].join('\n'),
        fragmentShader: [
            'varying float vAlpha;',
            'void main() {',
            '    vec2 c = gl_PointCoord - 0.5;',
            '    float r = dot(c, c);',
            '    if (r > 0.25) discard;',
            '    float a = vAlpha * (1.0 - r * 4.0);',
            '    gl_FragColor = vec4(vec3(0.62, 0.88, 1.0) * a, a);',
            '}'
        ].join('\n'),
        transparent: true,
        blending: THREE.AdditiveBlending,
        depthWrite: false
    });
    const particles = new THREE.Points(particleGeometry, particleMaterial);
    particles.frustumCulled = false;
    root.add(particles);

    // --- Interaction state ---
    const basePitch = 0.28;
    const state = {
        yaw: -0.6,
        pitch: basePitch,
        velYaw: 0,
        dragging: false,
        pointerId: null,
        lastX: 0,
        lastY: 0,
        lastMoveTime: 0,
        downX: 0,
        downY: 0,
        lastInteraction: -Infinity,
        hover: 0,
        hoverTarget: 0,
        tiltX: 0,
        tiltY: 0,
        tiltTargetX: 0,
        tiltTargetY: 0,
        pulse: 1,
        scanStart: 0.6
    };
    const SCAN_DURATION = 2.4;
    const SCAN_INTERVAL = reduceMotion ? Infinity : 5.5;
    const AUTO_SPIN = reduceMotion ? 0 : 0.35;

    function now() { return performance.now() / 1000; }

    function onInteract() {
        state.lastInteraction = now();
        if (typeof options.onInteract === 'function') options.onInteract();
    }

    function triggerPulse() {
        state.pulse = 0;
        state.scanStart = clock.elapsedTime;
    }

    canvas.addEventListener('pointerdown', function(event) {
        state.dragging = true;
        state.pointerId = event.pointerId;
        state.lastX = state.downX = event.clientX;
        state.lastY = state.downY = event.clientY;
        state.lastMoveTime = event.timeStamp;
        state.velYaw = 0;
        try { canvas.setPointerCapture(event.pointerId); } catch (e) { /* not capturable */ }
        container.classList.add('is-dragging');
        onInteract();
    });

    canvas.addEventListener('pointermove', function(event) {
        const rect = canvas.getBoundingClientRect();
        if (state.dragging && event.pointerId === state.pointerId) {
            const dx = event.clientX - state.lastX;
            const dy = event.clientY - state.lastY;
            const dt = Math.max((event.timeStamp - state.lastMoveTime) / 1000, 0.008);
            state.yaw += dx * 0.01;
            state.pitch = THREE.MathUtils.clamp(state.pitch + dy * 0.006, -0.5, 0.9);
            state.velYaw = THREE.MathUtils.lerp(state.velYaw, (dx * 0.01) / dt, 0.5);
            state.lastX = event.clientX;
            state.lastY = event.clientY;
            state.lastMoveTime = event.timeStamp;
            onInteract();
        } else if (event.pointerType === 'mouse') {
            state.tiltTargetY = ((event.clientX - rect.left) / rect.width - 0.5) * 0.35;
            state.tiltTargetX = ((event.clientY - rect.top) / rect.height - 0.5) * 0.2;
            state.hoverTarget = 1;
        }
    });

    function endDrag(event, cancelled) {
        if (!state.dragging || event.pointerId !== state.pointerId) return;
        state.dragging = false;
        container.classList.remove('is-dragging');
        // Treat a still release as a tap.
        if (!cancelled && Math.hypot(event.clientX - state.downX, event.clientY - state.downY) < 6) {
            state.velYaw = 0;
            triggerPulse();
        }
        // Drop stale momentum if the pointer paused before release.
        if (event.timeStamp - state.lastMoveTime > 120) state.velYaw = 0;
    }
    canvas.addEventListener('pointerup', function(event) { endDrag(event, false); });
    canvas.addEventListener('pointercancel', function(event) { endDrag(event, true); });
    canvas.addEventListener('pointerleave', function() {
        state.tiltTargetX = 0;
        state.tiltTargetY = 0;
        state.hoverTarget = 0;
    });

    container.addEventListener('keydown', function(event) {
        const key = event.key;
        if (key === 'ArrowLeft' || key === 'ArrowRight') {
            state.velYaw += key === 'ArrowLeft' ? -2.2 : 2.2;
        } else if (key === 'ArrowUp' || key === 'ArrowDown') {
            state.pitch = THREE.MathUtils.clamp(state.pitch + (key === 'ArrowUp' ? -0.15 : 0.15), -0.5, 0.9);
        } else if (key === 'Enter' || key === ' ') {
            triggerPulse();
        } else {
            return;
        }
        event.preventDefault();
        onInteract();
    });
    container.addEventListener('focus', function() { state.hoverTarget = 1; });
    container.addEventListener('blur', function() { state.hoverTarget = 0; });

    // --- Sizing ---
    function resize() {
        const width = container.clientWidth;
        const height = container.clientHeight;
        if (!width || !height) return;
        renderer.setSize(width, height, false);
        camera.aspect = width / height;
        // Pull back on narrow boxes so the rings never clip at the sides.
        camera.position.z = camera.aspect < 0.9 ? baseCameraZ * (0.9 / camera.aspect) : baseCameraZ;
        camera.updateProjectionMatrix();
    }
    const resizeObserver = new ResizeObserver(resize);
    resizeObserver.observe(container);
    resize();

    // --- Loop ---
    const clock = new THREE.Clock();
    function tick() {
        const dt = Math.min(clock.getDelta(), 0.05);
        const t = clock.elapsedTime;
        shared.uTime.value = t;

        const idle = now() - state.lastInteraction > 2.5;
        if (!state.dragging) {
            if (idle) {
                state.velYaw = THREE.MathUtils.lerp(state.velYaw, AUTO_SPIN, 1 - Math.exp(-dt * 1.2));
                state.pitch = THREE.MathUtils.lerp(state.pitch, basePitch, 1 - Math.exp(-dt * 1.5));
            } else {
                state.velYaw *= Math.exp(-dt * 2.5);
            }
            state.yaw += state.velYaw * dt;
        }
        spin.rotation.set(state.pitch, state.yaw, 0);

        const ease = 1 - Math.exp(-dt * 5);
        state.tiltX = THREE.MathUtils.lerp(state.tiltX, state.tiltTargetX, ease);
        state.tiltY = THREE.MathUtils.lerp(state.tiltY, state.tiltTargetY, ease);
        state.hover = THREE.MathUtils.lerp(state.hover, state.hoverTarget, ease);
        root.rotation.set(state.tiltX, state.tiltY, 0);
        root.position.y = reduceMotion ? 0 : Math.sin(t * 1.1) * 0.05;

        rimMaterial.uniforms.uIntensity.value = 0.5 + state.hover * 0.35;

        // Scan sweeps bottom -> top, then rests until the next interval.
        const scanT = (t - state.scanStart) / SCAN_DURATION;
        if (scanT >= 0 && scanT <= 1) {
            shared.uScanY.value = THREE.MathUtils.lerp(-1.05, 1.05, scanT);
            shared.uScanGlow.value = Math.sin(scanT * Math.PI);
        } else {
            shared.uScanGlow.value = 0;
            if (t - state.scanStart > SCAN_INTERVAL) state.scanStart = t;
        }

        rings.forEach(function(ring) { ring.orbiter.rotation.z += ring.speed * dt; });

        // Tap feedback: pulse ring + a small springy bounce.
        if (state.pulse < 1) {
            state.pulse = Math.min(1, state.pulse + dt / 0.9);
            const p = state.pulse;
            pulseRing.scale.setScalar(1 + p * 1.6);
            pulseRing.material.opacity = 0.7 * (1 - p);
            tooth.scale.setScalar(1 + Math.sin(p * Math.PI) * 0.06 * (1 - p));
            rim.scale.setScalar(1.015 * tooth.scale.x);
        } else {
            pulseRing.material.opacity = 0;
        }

        renderer.render(scene, camera);
    }

    // Only render while the hero is visible and the tab is in the foreground.
    let onScreen = true;
    function updateLoop() {
        const shouldRun = onScreen && !document.hidden;
        renderer.setAnimationLoop(shouldRun ? tick : null);
        if (shouldRun) clock.getDelta();
    }
    const visibilityObserver = new IntersectionObserver(function(entries) {
        onScreen = entries[0].isIntersecting;
        updateLoop();
    });
    visibilityObserver.observe(container);
    document.addEventListener('visibilitychange', updateLoop);

    tick();
    updateLoop();
    requestAnimationFrame(function() { container.classList.add('is-ready'); });

    return function destroy() {
        renderer.setAnimationLoop(null);
        resizeObserver.disconnect();
        visibilityObserver.disconnect();
        document.removeEventListener('visibilitychange', updateLoop);
        scene.traverse(function(obj) {
            if (obj.geometry) obj.geometry.dispose();
            if (obj.material) obj.material.dispose();
        });
        glowTexture.dispose();
        envTarget.dispose();
        renderer.dispose();
        canvas.remove();
    };
}
