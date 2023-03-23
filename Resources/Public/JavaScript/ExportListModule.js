define([], function() {
    var ExportListModule = {
        foo: 'bar'
    };

    ExportListModule.init = function() {
        // do init stuff
        alert();
    };

    // To let the module be a dependency of another module, we return our object
    return ExportListModule;
});