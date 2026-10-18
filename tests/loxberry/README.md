# enphase2lox PHP tests

Run from the repository root with PHP 7.4.33 or later:

```sh
php tests/loxberry/run.php
php tests/loxberry/ui-smoke.php
```

The suite requires the PHP SimpleXML extension already used by the plugin. It uses no
Composer packages, real credentials, network connections, Envoy, LoxBerry installation,
or MQTT broker. HTTP requests use an injected fake client; MQTT uses a temporary local
PHP executable that captures arguments, stdin and its private configuration. Temporary
files are removed at shutdown. Assertions throw exceptions and remain enabled even
when `zend.assertions` is disabled.

`ui-smoke.php` also uses a local loopback HTTP server with stubbed LoxBerry SDK and
service controller. It checks HTML escaping, CSRF rejection, configuration changes
and password retention/deletion. It never changes a real service.

To verify with the exact target version in an isolated development container:

```sh
docker run --rm --network none --read-only \
  --tmpfs /tmp:rw,exec,nosuid,size=64m \
  -v "$PWD:/app:ro" -w /app php:7.4.33-cli php tests/loxberry/run.php
docker run --rm --network none --read-only \
  --tmpfs /tmp:rw,exec,nosuid,size=64m \
  -v "$PWD:/app:ro" -w /app php:7.4.33-cli php tests/loxberry/ui-smoke.php
```

Docker is only a developer test option; the plugin does not require it. The temporary
filesystem permits execution because the MQTT fixture is a temporary PHP executable.

The full CLI/service lifecycle test runs in its own disposable PHP 7.4.33 container:

```sh
bash tests/loxberry/lifecycle-smoke.sh
```

It copies the real plugin into a temporary `custom-berry-root` with a suffixed plugin
folder, runs the worker as `nobody`, reads an FW5 SSE stream from a loopback Envoy
simulator and captures MQTT stdin through a test executable. Minimal core SDK fixtures
provide the central MQTT connection, which must override legacy broker settings.
It checks exact JSON, duplicate-start prevention, responsive stop, restart and disabled
start. Real install, upgrade and uninstall hooks receive the custom home through
installer argument 5 without `LBHOMEDIR`; configuration and token contents must survive.
The real boot hook is tested both with its installed home token and with `LBHOMEDIR`,
including folder lookup and no remaining worker after uninstall. The image lacks
`runuser`, so that one program is a test adapter mapping `loxberry` to `nobody`.
The container has no external network; temporary binary paths are created only inside
that disposable container, never on the host.

Password parity fixtures come from the original `passwordCalc.py`. To verify them with
Python's standard library (optional; not needed to run the PHP suite):

```sh
python3 tests/loxberry/password_vectors.py > /tmp/enphase2lox-password-vectors.json
diff -u tests/loxberry/password-vectors.json /tmp/enphase2lox-password-vectors.json
```

The generator parses the original helper with `ast` and executes only imports,
functions and their required globals. It never imports `envoy_to_mqtt.py`, reads live
configuration or runs the helper's top-level print statement.
