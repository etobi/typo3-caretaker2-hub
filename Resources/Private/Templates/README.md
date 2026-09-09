# Template file extensions

Hub: `*.fluid.html` — the v14 convention, and the hub requires v14.

Agent: `*.html` — has to carry v11 through v14, and older versions do not know
`.fluid.html`. The v14 resolver tries both, in this order: `Name.fluid.html`,
then `Name.html`.
