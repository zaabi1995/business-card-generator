<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fabric.js CDN Test</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 800px; margin: 40px auto; padding: 20px; }
        .test { padding: 15px; margin: 10px 0; border-radius: 8px; border: 1px solid #ddd; }
        .success { background: #d4edda; border-color: #28a745; }
        .error { background: #f8d7da; border-color: #dc3545; }
        .loading { background: #fff3cd; border-color: #ffc107; }
        code { background: #f4f4f4; padding: 2px 6px; border-radius: 4px; font-size: 12px; }
        canvas { border: 1px solid #ccc; margin-top: 10px; }
    </style>
</head>
<body>
    <h1>Fabric.js CDN Test</h1>
    <p>Testing different CDN URLs to find a working one...</p>
    
    <div id="results"></div>
    
    <h2>Working Canvas (if any)</h2>
    <canvas id="testCanvas" width="300" height="150"></canvas>
    
    <script>
        const cdnUrls = [
            // jsdelivr variations
            'https://cdn.jsdelivr.net/npm/fabric@7.1.0/dist/fabric.min.js',
            'https://cdn.jsdelivr.net/npm/fabric@7.1.0/dist/fabric.js',
            'https://cdn.jsdelivr.net/npm/fabric@7.1.0/dist/index.min.js',
            'https://cdn.jsdelivr.net/npm/fabric@7.1.0/dist/index.js',
            'https://cdn.jsdelivr.net/npm/fabric@7/dist/fabric.min.js',
            // unpkg variations
            'https://unpkg.com/fabric@7.1.0/dist/fabric.min.js',
            'https://unpkg.com/fabric@7.1.0/dist/fabric.js',
            'https://unpkg.com/fabric@7/dist/fabric.min.js',
            // cdnjs (might have older version)
            'https://cdnjs.cloudflare.com/ajax/libs/fabric.js/5.3.1/fabric.min.js',
            // Latest
            'https://cdn.jsdelivr.net/npm/fabric@latest/dist/fabric.min.js',
        ];
        
        const resultsDiv = document.getElementById('results');
        
        async function testUrl(url) {
            const div = document.createElement('div');
            div.className = 'test loading';
            div.innerHTML = `<strong>Testing:</strong> <code>${url}</code><br><span>Loading...</span>`;
            resultsDiv.appendChild(div);
            
            return new Promise((resolve) => {
                // Clear any existing fabric
                if (window.fabric) {
                    delete window.fabric;
                }
                
                const script = document.createElement('script');
                script.src = url;
                
                script.onload = () => {
                    setTimeout(() => {
                        if (typeof fabric !== 'undefined' && fabric.Canvas) {
                            div.className = 'test success';
                            div.innerHTML = `<strong>✅ SUCCESS:</strong> <code>${url}</code><br>
                                <span>fabric.version: ${fabric.version || 'unknown'}</span><br>
                                <span>fabric.Canvas: ${typeof fabric.Canvas}</span>`;
                            
                            // Try to create a canvas
                            try {
                                const canvas = new fabric.Canvas('testCanvas');
                                const text = new fabric.IText('It works!', {
                                    left: 50, top: 50, fontSize: 24, fill: '#2563eb'
                                });
                                canvas.add(text);
                                canvas.renderAll();
                                div.innerHTML += '<br><strong>Canvas test: PASSED</strong>';
                            } catch (e) {
                                div.innerHTML += '<br><strong>Canvas test failed:</strong> ' + e.message;
                            }
                            
                            resolve({ url, success: true });
                        } else {
                            div.className = 'test error';
                            div.innerHTML = `<strong>❌ LOADED but no fabric:</strong> <code>${url}</code><br>
                                <span>typeof fabric: ${typeof fabric}</span>`;
                            resolve({ url, success: false });
                        }
                    }, 100);
                };
                
                script.onerror = () => {
                    div.className = 'test error';
                    div.innerHTML = `<strong>❌ FAILED to load:</strong> <code>${url}</code>`;
                    resolve({ url, success: false });
                };
                
                document.head.appendChild(script);
            });
        }
        
        async function runTests() {
            for (const url of cdnUrls) {
                const result = await testUrl(url);
                if (result.success) {
                    console.log('Found working URL:', url);
                    // Don't stop - test all URLs for reference
                }
                // Small delay between tests
                await new Promise(r => setTimeout(r, 500));
            }
        }
        
        runTests();
    </script>
</body>
</html>
