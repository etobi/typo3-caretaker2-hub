# Template-Endungen

Hub: `*.fluid.html` — v14-Konvention, der Hub setzt v14 voraus.

Agent: `*.html` — muss von v11 bis v14 tragen, und `.fluid.html` kennen
ältere Fassungen nicht. Der Resolver in v14 probiert beide Varianten, in
dieser Reihenfolge: `Name.fluid.html`, dann `Name.html`.
