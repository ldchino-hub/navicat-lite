module.exports = {
  apps: [{
    name: 'navicat-php',
    script: 'php',
    args: '-S 0.0.0.0:8080 index.php',
    cwd: '/home/luisjimenez/navicat-php-1.0.0/public',
    interpreter: 'none',
    autorestart: true,
    max_restarts: 10,
    env: {},
  }],
};
